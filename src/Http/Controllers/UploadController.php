<?php

namespace GiorgioImSocket\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class UploadController extends Controller
{
    public function store(Request $request): array
    {
        $request->validate(['kind' => 'required|in:image,file,audio', 'file' => 'required|file|max:'.config('im.upload_max_kb')]);
        $kind = $request->input('kind');
        $rules = match ($kind) {
            'image' => 'file|mimes:jpg,jpeg,png,gif,webp,avif|max:2048',
            'audio' => 'file|mimetypes:audio/webm,video/webm,audio/ogg,application/ogg,audio/mpeg,audio/mp4,video/mp4,audio/x-wav,audio/wav|max:'.config('im.upload_max_kb'),
            default => 'file|mimes:txt,pdf,zip,doc,docx,xls,xlsx,ppt,pptx,csv,jpg,jpeg,png,webp|max:'.config('im.upload_max_kb'),
        };
        $request->validate(['file' => $rules]);
        $file = $request->file('file');
        $path = $file->store('im', config('im.disk'));
        try {
            return DB::transaction(function () use ($request, $file, $path, $kind) {
                $id = DB::table('im_uploads')->insertGetId([
                    'user_id' => (int) $request->user()->getAuthIdentifier(), 'url' => 'pending:'.bin2hex(random_bytes(16)),
                    'kind' => $kind, 'name' => $file->getClientOriginalName(), 'path' => $path, 'mime' => $file->getMimeType(),
                ]);
                $url = URL::signedRoute('im.files.show', ['upload' => $id]);
                DB::table('im_uploads')->where('id', $id)->update(['url' => $url]);

                return ['url' => $url];
            });
        } catch (\Throwable $error) {
            Storage::disk(config('im.disk'))->delete($path);
            throw $error;
        }
    }

    public function show(int $upload)
    {
        $file = DB::table('im_uploads')->where('id', $upload)->first();
        abort_unless($file, 404);

        return Storage::disk(config('im.disk'))->response($file->path, $file->name, [
            'Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $file->kind === 'file' ? 'attachment' : 'inline');
    }
}
