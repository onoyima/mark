<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\NyscSession;
use App\Models\AdminSetting;

class NyscSessionController extends Controller
{
    public function index()
    {
        $sessions = NyscSession::orderBy('start_at', 'desc')->get();
        $activeId = AdminSetting::get('active_session_id');
        return response()->json([
            'success' => true,
            'sessions' => $sessions,
            'active_session_id' => $activeId,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'start_at' => 'required|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'status' => 'nullable|string|max:50',
            'activate' => 'sometimes|boolean',
        ]);

        $session = NyscSession::create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'start_at' => $data['start_at'],
            'end_at' => $data['end_at'] ?? null,
            'status' => $data['status'] ?? 'open',
            'is_active' => false,
        ]);

        if (!empty($data['activate'])) {
            $this->activate($request, $session->id);
        }

        return response()->json(['success' => true, 'session' => $session]);
    }

    public function update(Request $request, int $id)
    {
        $session = NyscSession::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'nullable|string|max:50',
            'start_at' => 'sometimes|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'status' => 'nullable|string|max:50',
            'is_active' => 'sometimes|boolean',
        ]);
        $session->update($data);
        return response()->json(['success' => true, 'session' => $session]);
    }

    public function activate(Request $request, int $id)
    {
        $session = NyscSession::findOrFail($id);
        NyscSession::where('id', '!=', $id)->update(['is_active' => false]);
        $session->is_active = true;
        $session->save();

        AdminSetting::set('active_session_id', (string)$session->id, 'number', 'Active NYSC session id', 'sessions');
        AdminSetting::set('active_session_name', $session->name, 'string', 'Active NYSC session name', 'sessions');
        AdminSetting::set('active_session_start', (string)$session->start_at, 'date', 'Active session start', 'sessions');
        AdminSetting::set('active_session_end', (string)($session->end_at ?? ''), 'date', 'Active session end', 'sessions');

        return response()->json(['success' => true, 'active_session_id' => $session->id]);
    }

    public function destroy(int $id)
    {
        $session = NyscSession::findOrFail($id);
        if ($session->is_active) {
            return response()->json(['success' => false, 'message' => 'Cannot delete active session'], 400);
        }
        $session->delete();
        return response()->json(['success' => true]);
    }

    public function active()
    {
        $activeId = AdminSetting::get('active_session_id');
        $session = $activeId ? NyscSession::find($activeId) : null;
        return response()->json(['success' => true, 'session' => $session]);
    }
}