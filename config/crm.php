<?php

return [
    'v2_enabled' => (bool) env('CRM_V2_ENABLED', false),
    'collected_documents_root' => env('CRM_COLLECTED_DOCUMENTS_ROOT', storage_path('app/private/coffrac-documents')),
    'collected_documents_manifest' => env('CRM_COLLECTED_DOCUMENTS_MANIFEST', storage_path('app/private/coffrac-documents-selection/documents.list0')),
];
