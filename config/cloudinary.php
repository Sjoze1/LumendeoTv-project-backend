<?php

return [
    'cloud_url' => env('CLOUDINARY_URL'), // REQUIRED for SDK to work

    'upload_preset' => env('CLOUDINARY_UPLOAD_PRESET', null),

    'cloud' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key'    => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
    ],

    'secure' => true,
];
