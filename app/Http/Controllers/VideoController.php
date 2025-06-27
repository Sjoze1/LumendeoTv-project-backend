<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class VideoController extends Controller
{
    public function index()
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('b2');

        $videos = Video::latest()->get()->map(function ($video) use ($disk) {
            $video->thumbnail_url = $disk->temporaryUrl($video->thumbnail_url, now()->addMinutes(30));
            $video->trailer_url = $disk->temporaryUrl($video->trailer_url, now()->addMinutes(30));
            $video->full_video_url = $disk->temporaryUrl($video->full_video_url, now()->addMinutes(30));
            return $video;
        });

        return response()->json($videos);
    }

    public function latest()
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('b2');

        $video = Video::latest()->first();

        if (!$video) {
            return response()->json(['error' => 'No video found'], 404);
        }

        $video->thumbnail_url = $disk->temporaryUrl($video->thumbnail_url, now()->addMinutes(30));
        $video->trailer_url = $disk->temporaryUrl($video->trailer_url, now()->addMinutes(30));
        $video->full_video_url = $disk->temporaryUrl($video->full_video_url, now()->addMinutes(30));

        return response()->json($video);
    }

    public function show($id)
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('b2');

        $video = Video::find($id);

        if (!$video) {
            return response()->json(['error' => 'Video not found'], 404);
        }

        $video->thumbnail_url = $disk->temporaryUrl($video->thumbnail_url, now()->addMinutes(30));
        $video->trailer_url = $disk->temporaryUrl($video->trailer_url, now()->addMinutes(30));
        $video->full_video_url = $disk->temporaryUrl($video->full_video_url, now()->addMinutes(30));

        return response()->json($video);
    }

    public function store(Request $request)
    {
        $disk = Storage::disk('b2');
    
        if ($request->hasFile('trailer') && $request->hasFile('full_video') && $request->hasFile('thumbnail')) {
            $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'trailer' => 'required|file|mimes:mp4',
                'full_video' => 'required|file|mimes:mp4',
                'thumbnail' => 'required|image',
            ]);
    
            // Store files on B2 disk and get paths
            $trailerPath = $request->file('trailer')->store('videos', 'b2');
            $fullPath = $request->file('full_video')->store('videos', 'b2');
            $thumbPath = $request->file('thumbnail')->store('thumbs', 'b2');
    
            Log::info('Stored file paths:', [
                'thumbnail' => $thumbPath,
                'trailer' => $trailerPath,
                'full_video' => $fullPath,
            ]);
    
            $video = Video::create([
                'title' => $request->title,
                'description' => $request->description,
                'thumbnail_url' => $thumbPath,
                'trailer_url' => $trailerPath,
                'full_video_url' => $fullPath,
            ]);
    
            // Generate temporary URLs for response
            $video->thumbnail_url = $this->generateTemporaryUrl($disk, $thumbPath);
            $video->trailer_url = $this->generateTemporaryUrl($disk, $trailerPath);
            $video->full_video_url = $this->generateTemporaryUrl($disk, $fullPath);
    
            return response()->json($video, 201);
        }
    
        // When URLs are sent directly (no files), just save as-is without generating temp URLs
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'thumbnail_url' => 'required|string|url',
            'trailer_url' => 'required|string|url',
            'full_video_url' => 'required|string|url',
        ]);
    
        $video = Video::create($validated);
    
        // Since these are full URLs already, just return them as is
        return response()->json($video, 201);
    }    
    
    // helper method:
    private function generateTemporaryUrl($disk, $path)
    {
        if (!$path || !$disk->exists($path)) {
            return null;
        }
        return $disk->temporaryUrl($path, now()->addMinutes(30));
    }    
    
    public function update(Request $request, $id)
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('b2');

        $video = Video::find($id);

        if (!$video) {
            return response()->json(['error' => 'Video not found'], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'thumbnail_url' => 'sometimes|string',
            'trailer_url' => 'sometimes|string',
            'full_video_url' => 'sometimes|string',
        ]);

        $video->update($validated);

        $video->thumbnail_url = $disk->temporaryUrl($video->thumbnail_url, now()->addMinutes(30));
        $video->trailer_url = $disk->temporaryUrl($video->trailer_url, now()->addMinutes(30));
        $video->full_video_url = $disk->temporaryUrl($video->full_video_url, now()->addMinutes(30));

        return response()->json($video);
    }

    public function destroy($id)
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('b2');

        $video = Video::find($id);

        if (!$video) {
            return response()->json(['error' => 'Video not found'], 404);
        }

        // Delete files from B2 storage
        $disk->delete([
            $video->thumbnail_url,
            $video->trailer_url,
            $video->full_video_url,
        ]);

        $video->delete();

        return response()->json(['message' => 'Video deleted']);
    }

    public function streamVideo($filename)
{
    /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
    $disk = Storage::disk('b2');

    $path = "videos/{$filename}";

    if (!$disk->exists($path)) {
        return response()->json(['error' => 'File not found'], 404);
    }

    $temporaryUrl = $disk->temporaryUrl($path, now()->addMinutes(30));

    return response()->json(['url' => $temporaryUrl]);
}

}
