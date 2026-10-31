<?php

return [
    'default' => 'sync',
    'connections' => ['sync' => ['driver' => 'sync']],
    'failed' => ['driver' => 'null'],
    'batching' => ['database' => 'mysql', 'table' => 'job_batches'],
];
