<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Api\Admin\AdminApi;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
    public function __construct()
    {
        Configuration::instance([
            'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
            'api_key' => env('CLOUDINARY_API_KEY'),
            'api_secret' => env('CLOUDINARY_API_SECRET'),
            'url_signature' => env('CLOUDINARY_URL_SIGNATURE', false),
        ]);
    }

    public function index()
    {
        return response()->json(Video::latest()->get());
    }

    public function latest()
    {
        $video = Video::latest()->first();
        if (!$video) {
            return response()->json(['error' => 'No video found'], 404);
        }
        return response()->json($video);
    }

    public function show($id)
    {
        $video = Video::find($id);
        if (!$video) {
            return response()->json(['error' => 'Video not found'], 404);
        }
        return response()->json($video);
    }

    public function store(Request $request)
    {
        if ($request->hasFile('trailer') && $request->hasFile('full_video') && $request->hasFile('thumbnail')) {
            $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'trailer' => 'required|file|mimes:mp4,mov,avi,wmv,flv,webm|max:102400', // 100MB
                'full_video' => 'required|file|mimes:mp4,mov,avi,wmv,flv,webm|max:5120000', // ~5GB
                'thumbnail' => 'required|image|max:10240', // 10MB
            ]);
    
            try {
                $uploadApi = new UploadApi();
    
                // Upload thumbnail
                $thumbnailFile = $request->file('thumbnail');
                $thumbUploadResult = $uploadApi->upload(
                    $thumbnailFile->getRealPath(),
                    [
                        'resource_type' => 'image',
                        'folder' => 'artful_kenya_thumbnails',
                        'public_id' => 'thumb_' . uniqid() . '_' . pathinfo($thumbnailFile->getClientOriginalName(), PATHINFO_FILENAME),
                        'quality' => 'auto',
                        'fetch_format' => 'auto',
                    ]
                );
                $thumbnailUrl = $thumbUploadResult['secure_url'];
                $thumbnailPublicId = $thumbUploadResult['public_id'];
    
                // Upload trailer
                $trailerFile = $request->file('trailer');
                $trailerUploadResult = $uploadApi->upload(
                    $trailerFile->getRealPath(),
                    [
                        'resource_type' => 'video',
                        'folder' => 'artful_kenya_trailers',
                        'public_id' => 'trailer_' . uniqid() . '_' . pathinfo($trailerFile->getClientOriginalName(), PATHINFO_FILENAME),
                        'eager' => [['format' => 'mp4', 'quality' => 'auto:eco', 'fetch_format' => 'auto']],
                        'eager_async' => true,
                    ]
                );
                $trailerUrl = $trailerUploadResult['secure_url'];
                $trailerPublicId = $trailerUploadResult['public_id'];
    
                // Upload full video
                $fullVideoFile = $request->file('full_video');
                $fullVideoUploadResult = $uploadApi->upload(
                    $fullVideoFile->getRealPath(),
                    [
                        'resource_type' => 'video',
                        'folder' => 'artful_kenya_full_videos',
                        'public_id' => 'full_' . uniqid() . '_' . pathinfo($fullVideoFile->getClientOriginalName(), PATHINFO_FILENAME),
                        'eager' => [['format' => 'mp4', 'quality' => 'auto:eco', 'fetch_format' => 'auto']],
                        'eager_async' => true,
                    ]
                );
                $fullVideoUrl = $fullVideoUploadResult['secure_url'];
                $fullVideoPublicId = $fullVideoUploadResult['public_id'];
    
                // Save to DB
                $video = Video::create([
                    'title' => $request->title,
                    'description' => $request->description,
                    'thumbnail_url' => $thumbnailUrl,
                    'trailer_url' => $trailerUrl,
                    'full_video_url' => $fullVideoUrl,
                    'cloudinary_public_ids' => json_encode([
                        'thumbnail' => $thumbnailPublicId,
                        'trailer' => $trailerPublicId,
                        'full_video' => $fullVideoPublicId,
                    ]),
                ]);
    
                return response()->json($video, 201);
    
            } catch (\Exception $e) {
                Log::error('Upload error in store method: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json(['error' => 'Failed to upload media. ' . $e->getMessage()], 500);
            }
        }
    
        // Fallback: direct URLs (optional)
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'thumbnail_url' => 'required|url',
            'trailer_url' => 'required|url',
            'full_video_url' => 'required|url',
            'cloudinary_public_ids' => 'sometimes|json',
        ]);
    
        $video = Video::create($validated);
        return response()->json($video, 201);
    }         

    public function update(Request $request, $id)
    {
        $video = Video::find($id);
        if (!$video) {
            return response()->json(['error' => 'Video not found'], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'thumbnail_url' => 'sometimes|url',
            'trailer_url' => 'sometimes|url',
            'full_video_url' => 'sometimes|url',
            'cloudinary_public_ids' => 'sometimes|json',
        ]);

        $video->update($validated);
        return response()->json($video);
    }

    public function destroy($id)
    {
        $video = Video::find($id);
        if (!$video) {
            return response()->json(['error' => 'Video not found'], 404);
        }

        try {
            if ($video->cloudinary_public_ids) {
                $publicIds = json_decode($video->cloudinary_public_ids, true);
                $uploadApi = new UploadApi();

                if (isset($publicIds['thumbnail'])) {
                    $uploadApi->destroy($publicIds['thumbnail'], ['resource_type' => 'image']);
                }
                if (isset($publicIds['trailer'])) {
                    $uploadApi->destroy($publicIds['trailer'], ['resource_type' => 'video']);
                }
                if (isset($publicIds['full_video'])) {
                    $uploadApi->destroy($publicIds['full_video'], ['resource_type' => 'video']);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to delete Cloudinary assets for video ID ' . $id . ': ' . $e->getMessage());
        }

        $video->delete();
        return response()->json(['message' => 'Video deleted successfully']);
    }
}
