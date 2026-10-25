#!/usr/bin/env bash
set -euo pipefail

# Builds the SPA, builds and pushes the production image with Cloud Build, points
# both Cloud Run jobs at that exact image digest (never the moving `:latest`
# tag, so a deploy is reproducible and revertible), runs the migrations with the
# new image, and only then switches the web service over.
# There are no long-lived queue workers to restart: `process-queue` is a short
# job started every couple of minutes, so its next execution already runs the
# new image; one that is in flight finishes on the old code within its 120 s cap.
# Run it through `make deploy`; every setting below can be overridden by env.

PROJECT="${GCP_PROJECT:-gym-trainer-api}"
REGION="${GCP_REGION:-southamerica-east1}"
IMAGE="${IMAGE:-us-central1-docker.pkg.dev/gym-trainer-api/gym-trainer/api}"
SPA_DIR="${SPA_DIR:-../gym-trainer-spa}"
SERVICE="${SERVICE:-gym-trainer-api}"
JOBS=(process-queue retry-failed-analysis)

cd "$(dirname "$0")/../.."

die() { echo "deploy: $*" >&2; exit 1; }

if [[ "${FORCE:-0}" != 1 ]]; then
    [[ "$(git rev-parse --abbrev-ref HEAD)" == main ]] || die "not on main (FORCE=1 to override)"
    [[ -z "$(git status --porcelain)" ]] || die "uncommitted changes (FORCE=1 to override)"
fi

[[ -d "$SPA_DIR" ]] || die "SPA repo not found at $SPA_DIR (set SPA_DIR)"

echo "==> Building the SPA"
(
    cd "$SPA_DIR"
    [[ -d node_modules ]] || npm ci
    # Same-origin deploy: an empty base URL makes the SPA call /api/v1 on its own host.
    VITE_API_URL= npm run build
)
rm -rf docker/production/spa-dist
cp -r "$SPA_DIR/dist" docker/production/spa-dist

echo "==> Building the image with Cloud Build"
gcloud builds submit --project "$PROJECT" --config docker/production/cloudbuild.yaml \
    --substitutions "_IMAGE=$IMAGE" .

digest="$(gcloud artifacts docker images describe "$IMAGE:latest" --project "$PROJECT" \
    --format='value(image_summary.fully_qualified_digest)')"
[[ -n "$digest" ]] || die "could not resolve the pushed image digest"

echo "==> Updating the jobs to $digest"
for job in "${JOBS[@]}"; do
    gcloud run jobs update "$job" --project "$PROJECT" --region "$REGION" --image "$digest"
done

echo "==> Running migrations"
gcloud run jobs execute "${JOBS[0]}" --project "$PROJECT" --region "$REGION" --wait \
    --args=artisan,migrate,--force

echo "==> Deploying the web service"
gcloud run deploy "$SERVICE" --project "$PROJECT" --region "$REGION" --image "$digest"

echo "==> Done. Recent errors:"
echo "gcloud logging read 'resource.type=\"cloud_run_revision\" AND severity>=ERROR' --project $PROJECT --freshness=10m --limit=10"
