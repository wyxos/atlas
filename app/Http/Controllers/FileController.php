<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class FileController extends Controller
{
    /**
     * Display a listing of the files.
     */
    public function index(Request $request): Response
    {
        // Check if the user is an admin
        if (!Auth::user()->is_admin) {
            abort(HttpResponse::HTTP_FORBIDDEN, 'Access denied. Only admins can access this page.');
        }

        $query = File::query();

        // Handle search
        $search = $request->input('query', '');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('filename', 'like', "%{$search}%")
                  ->orWhere('path', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%");
            });
        }

        // Handle not_found filter
        $notFoundFilter = $request->boolean('not_found');
        if ($notFoundFilter) {
            $query->where('not_found', true);
        }

        $files = $query->select([
                'id',
                'path',
                'filename as name',
                'ext as type',
                'mime_type',
                'not_found',
                'created_at'
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Files/Index', [
            'files' => $files,
            'search' => $search,
            'notFoundFilter' => $notFoundFilter,
        ]);
    }

    /**
     * Remove the specified file from storage.
     */
    public function destroy(File $file)
    {
        // Check if the user is an admin
        if (!Auth::user()->is_admin) {
            abort(HttpResponse::HTTP_FORBIDDEN, 'Access denied. Only admins can delete files.');
        }

        $file->delete();

        return redirect()->route('files.index')->with('success', 'File deleted successfully.');
    }
}
