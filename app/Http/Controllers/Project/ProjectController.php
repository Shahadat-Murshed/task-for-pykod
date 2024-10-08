<?php

namespace App\Http\Controllers\Project;

use App\Enums\Project\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ProjectStoreRequest;
use App\Http\Requests\Project\ProjectUpdateRequest;
use App\Jobs\SendMailJob;
use App\Jobs\SendMailOnStatusChangeJob;
use App\Models\Project\Project;
use App\Models\User;
use App\Traits\File\HasFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    use HasFile;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $userRole = Auth::user()->role;
        $projects = Project::with('users:id,name')
            ->when($userRole === "admin", function ($query) {
                return $query;
            }, function ($query) {
                return $query->whereHas('users', function ($subquery) {
                    $subquery->where('users.id', Auth::id());
                });
            })
            ->orderByDesc('id')
            ->get();

        return view('pages.projects.index', compact('projects'));
    }
    public function deletedList()
    {
        $userRole = Auth::user()->role;
        $projects = Project::onlyTrashed()->with('users:id,name')
            ->when($userRole === "admin", function ($query) {
                return $query;
            }, function ($query) {
                return $query->whereHas('users', function ($subquery) {
                    $subquery->where('users.id', Auth::id());
                });
            })
            ->orderByDesc('id')
            ->get();

        return view('pages.projects.recycle', compact('projects'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $users = User::select('id', 'name')->where('role', '!=', 'admin')->get();
        $statuses = Status::array();
        return view('pages.projects.create', compact('users', 'statuses'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ProjectStoreRequest $request)
    {
        $validatedData = $request->validated();
        $admin = User::where('role', 'admin')->firstOrFail();
        $user = User::findOrFail($validatedData['staff']);

        if (isset($validatedData['file'])) {
            $file = $this->saveFile(
                prefix: 'projects',
                name: $validatedData['name'],
                file: $validatedData['file'],
                custom: null,
                other: time(),
                directory: 'projects',
            );

            $validatedData = [...$validatedData, 'file' => $file];
        }

        $project = Project::create([...$validatedData, 'created_by' => auth()->id()]);
        $project->users()->attach($user->id, ['assigned_by' => auth()->id()]);

        dispatch(new SendMailJob($admin->email, $admin->name, $project->name, Auth::user()->name));

        notyf()->success('Project Created Successfully');
        return redirect()->route('projects.index');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $project = Project::with('users')->findOrFail($id);
        $users = User::select('id', 'name')->where('role', '!=', 'admin')->get();
        $statuses = Status::array();

        return view('pages.projects.edit', compact('project', 'users', 'statuses'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ProjectUpdateRequest $request, string $id)
    {
        $validatedData = $request->validated();

        $admin = User::where('role', 'admin')->firstOrFail();
        $project = Project::findOrFail($id);
        $oldStatus = $project->status;
        $user = User::findOrFail($validatedData['staff']);

        if (isset($validatedData['file'])) {
            $oldFile = $project->file;

            $file = $this->saveFile(
                prefix: 'projects',
                name: $validatedData['name'],
                file: $validatedData['file'],
                custom: null,
                other: time(),
                directory: 'projects',
            );
            $validatedData = [...$validatedData, 'file' => $file];
        }

        $project->update($validatedData);
        $project->users()->detach();
        $project->users()->attach($user->id, ['assigned_by' => auth()->id()]);

        !isset($file) ?: $this->removeFile($oldFile);

        if ($oldStatus != $validatedData['status']) {
            dispatch(new SendMailOnStatusChangeJob($admin->email, $admin->name, $project->name, Auth::user()->name, $validatedData['status']));
        }

        notyf()->success('Project Updated Successfully');
        return redirect()->route('projects.index');
    }

    public function restoreMultiple(Request $request)
    {
        $ids = $request->checked_ids;

        if (empty($ids)) {
            return response()->json(['message' => 'No project IDs provided.'], 400);
        }

        Project::withTrashed()->whereIn('id', $ids)->restore();

        Project::whereIn('id', $ids)->update(['status' => 'active']);

        return response()->json(
            [
                'status' => 'success',
                'message' => 'Projects restored successfully!',
            ],
            Response::HTTP_OK
        );
    }

    public function restore(string $id)
    {
        $project = Project::withTrashed()->findOrFail($id);
        $project->status = Status::ACTIVE;
        $project->save();
        $project->restore();

        return response()->json(
            [
                'status' => 'success',
                'message' => 'Project restored successfully!',
            ],
            Response::HTTP_OK
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $project = Project::findOrFail($id);
        $project->status = Status::INACTIVE;
        $project->save();

        $project->delete();

        return response()->json(
            [
                'status' => 'success',
                'message' => 'Project deleted successfully!',
            ],
            Response::HTTP_OK
        );
    }
}
