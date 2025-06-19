<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VideoController extends Controller
{
    public function index()
    {
        return response()->json(Video::latest()->get());
    }

    public function streamVideo($filename)
{
    $path = storage_path("app/public/videos/{$filename}");

    if (!file_exists($path)) {
        return response()->json(['error' => 'File not found.'], 404);
    }

    $stream = function () use ($path) {
        $file = fopen($path, 'rb');
        $size = filesize($path);

        $start = 0;
        $end = $size - 1;

        if (isset($_SERVER['HTTP_RANGE'])) {
            if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
                $start = intval($matches[1]);
                if (!empty($matches[2])) {
                    $end = intval($matches[2]);
                }
            }
        }

        $length = $end - $start + 1;

        header("Content-Type: video/mp4");
        header("Accept-Ranges: bytes");
        header("Content-Length: $length");
        header("Content-Range: bytes $start-$end/$size");

        fseek($file, $start);

        $bufferSize = 1024 * 8; // 8KB buffer
        while (!feof($file) && ($pos = ftell($file)) <= $end) {
            if ($pos + $bufferSize > $end) {
                $bufferSize = $end - $pos + 1;
            }
            echo fread($file, $bufferSize);
            flush();
        }

        fclose($file);
    };

    return response()->stream($stream, 206, [
        'Content-Type' => 'video/mp4',
        'Accept-Ranges' => 'bytes',
    ]);
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
        // If uploading files
        if ($request->hasFile('trailer') && $request->hasFile('full_video') && $request->hasFile('thumbnail')) {
            $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'trailer' => 'required|file|mimes:mp4',
                'full_video' => 'required|file|mimes:mp4',
                'thumbnail' => 'required|image',
            ]);

            $trailerPath = $request->file('trailer')->store('videos', 'public');
            $fullPath = $request->file('full_video')->store('videos', 'public');
            $thumbPath = $request->file('thumbnail')->store('thumbs', 'public');            

            $video = Video::create([
                'title' => $request->title,
                'description' => $request->description,
                'thumbnail_url' => Storage::url($thumbPath),
                'trailer_url' => Storage::url($trailerPath),
                'full_video_url' => Storage::url($fullPath),
            ]);

            return response()->json($video, 201);
        }

        // If JSON URLs are sent instead
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'thumbnail_url' => 'required|url',
            'trailer_url' => 'required|url',
            'full_video_url' => 'required|url',
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

        $video->delete();

        return response()->json(['message' => 'Video deleted']);
    }
}
