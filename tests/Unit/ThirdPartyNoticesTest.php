<?php

// Catalogue spec TC-40
it('carries the MIT notice of the exercises dataset', function () {
    $notice = file_get_contents(dirname(__DIR__, 2).'/THIRD_PARTY_NOTICES.md');

    expect($notice)
        ->toContain('MIT License')
        ->toContain('Copyright (c) 2026 Hasan Emir Yıldırım')
        ->toContain('Permission is hereby granted, free of charge')
        ->toContain('not included')
        ->toContain('7455efae41b330c265e7cd4b78dfa848e7ce5ebd');
});
