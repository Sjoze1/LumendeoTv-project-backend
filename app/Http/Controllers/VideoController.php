<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
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
