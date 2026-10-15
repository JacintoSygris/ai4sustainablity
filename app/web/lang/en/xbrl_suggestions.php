<?php

return [
    'description' => 'Review offline XBRL concept suggestions; requires human acceptance.',
    'usage' => 'Select --list, --accept=ID or --reject=ID. Decisions require --reviewer with an accountable operator alias.',
    'headers' => ['Suggestion ID', 'ESRS datapoint', 'XBRL concept', 'Uncalibrated score', 'Decision'],
    'pending_review' => 'Pending review',
    'accepted' => 'Accepted',
    'stale_approval' => 'Expired acceptance; review again',
    'rejected' => 'Rejected',
    'recorded' => 'Decision recorded. The map uses valid acceptances only when the overlay is enabled.',
    'failed' => 'Review failed. Check the assets, ID, alias and previous decisions.',
];
