<?php

// config/cloudinary.php

return [
    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
    'api_key' => env('CLOUDINARY_API_KEY'),
    'api_secret' => env('CLOUDINARY_API_SECRET'),
    'url_signature' => env('CLOUDINARY_URL_SIGNATURE', false), // Defaults to false if not set in .env
];
