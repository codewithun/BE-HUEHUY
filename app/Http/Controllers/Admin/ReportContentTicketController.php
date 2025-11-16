<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReportContentTicket;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ReportContentTicketController extends Controller
{
    // ========================================>
    // ## Display a listing of the resource.
    // ========================================>
    public function index(Request $request)
    {
        // ? Initial params
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);

        // ? Preparation
        $columnAliases = [];

        // ? Begin
        $model = new ReportContentTicket();
        $query = ReportContentTicket::with('user_reporter', 'ad', 'ad.cube');

        // ? When search
        if ($request->get("search") != "") {
            $query = $this->search($request->get("search"), $model, $query);
        } else {
            $query = $query;
        }

        // ? When Filter
        if ($filter) {
            $filters = json_decode($filter);
            foreach ($filters as $column => $value) {
                $query = $this->filter($this->remark_column($column, $columnAliases), $value, $model, $query);
            }
        }

        // ? Sort & executing with pagination
        $query = $query->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection)
            ->select($model->selectable)->paginate($paginate);

        // ? When empty
        if (empty($query->items())) {
            return response([
                "message" => "empty data",
                "data" => [],
            ], 200);
        }

        // ? When success
        return response([
            "message" => "success",
            "data" => $query->all(),
            "total_row" => $query->total(),
        ]);
    }

    // ===============================================>
    // ## Remove the specified resource from storage.
    // ===============================================>
    public function destroy(string $id)
    {
        // ? Initial
        $model = ReportContentTicket::findOrFail($id);

        // ? Executing
        try {
            $model->delete();
        } catch (\Throwable $th) {
            return response([
                "message" => "Error: server side having problem!"
            ], 500);
        }

        return response([
            "message" => "Success",
            "data" => $model
        ]);
    }

    // ===============================================>
    // ## Update status the specified resource from storage.
    // ===============================================>
    public function updateStatus(Request $request, string $id)
    {
        try {
            // ? Initial
            DB::beginTransaction();
            $model = ReportContentTicket::with('ad', 'ad.cube')->findOrFail($id);

            // ? Validate request
            $validation = $this->validation($request->all(), [
                'status' => ['required', Rule::in(['pending', 'rejected', 'accepted'])],
            ]);

            if ($validation) return $validation;

            // ? Update data
            $model->status = $request->status;

            // ? Executing
            try {
                $model->save();
            } catch (\Throwable $th) {
                DB::rollBack();
                return response([
                    "message" => "Error: failed to update report ticket status data",
                    "error" => $th->getMessage()
                ], 500);
            }

            switch ($request->status) {
                case 'accepted': {
                        // Check if ad exists
                        if (!$model->ad) {
                            DB::rollBack();
                            return response([
                                "message" => "Error: Associated ad not found",
                            ], 404);
                        }

                        // * Inactive the selected ad & cube
                        $ad = $model->ad;
                        $ad->status = 'inactive';
                        try {
                            $ad->save();
                        } catch (\Throwable $th) {
                            DB::rollBack();
                            return response([
                                "message" => "Error: failed to update ad status",
                                "error" => $th->getMessage()
                            ], 500);
                        }

                        // Check if cube exists before updating
                        if ($ad->cube) {
                            $cube = $ad->cube;
                            $cube->status = 'inactive';
                            try {
                                $cube->save();
                            } catch (\Throwable $th) {
                                DB::rollBack();
                                return response([
                                    "message" => "Error: failed to update cube status",
                                    "error" => $th->getMessage()
                                ], 500);
                            }
                        }

                        // * Set notif to remind cube owner (do not fail main flow if notif fails)
                        if ($ad->cube) {
                            $notificationMessage = "Promo iklan $ad->title pada kubus " . $ad->cube->code . " telah melanggar ketentuan Huehuy dan telah di-non-aktifkan. Periksa kembali iklan Anda.";

                            $notificationType = '';
                            if ($ad->cube->user_id) {
                                $notificationType = 'USER';
                            } else if ($ad->cube->corporate_id) {
                                $notificationType = 'CORPORATE';
                            } else if ($ad->cube->world_id) {
                                $notificationType = 'WORLD_OWNER';
                            }

                            if ($notificationType) {
                                try {
                                    $createNotif = NotificationService::create($notificationType, $notificationMessage, [
                                        'grab_id' => $model->id,
                                        'user' => $ad->cube->user_id ? [$ad->cube->user_id] : null,
                                        'corporate' => $ad->cube->corporate_id ?? null,
                                        'world' => $ad->cube->world_id ?? null,
                                    ], 'merchant');

                                    if (is_array($createNotif) && ($createNotif['error'] ?? false) === true) {
                                        Log::warning('Notification creation failed on report accept', [
                                            'report_id' => $model->id,
                                            'ad_id' => $ad->id,
                                            'cube_id' => $ad->cube->id ?? null,
                                            'message' => $createNotif['message'] ?? null,
                                            'error' => (string)($createNotif['errorMessage'] ?? ''),
                                        ]);
                                        // Do not rollback; continue main success flow
                                    }
                                } catch (\Throwable $notifyEx) {
                                    Log::error('Exception creating notification on report accept', [
                                        'report_id' => $model->id,
                                        'ad_id' => $ad->id,
                                        'cube_id' => $ad->cube->id ?? null,
                                        'error' => $notifyEx->getMessage(),
                                        'line' => $notifyEx->getLine(),
                                        'file' => $notifyEx->getFile(),
                                    ]);
                                    // Silently continue; notification failure should not break main flow
                                }
                            }
                        }
                    }
                    break;
            }

            DB::commit();

            return response([
                'message' => 'Success',
                'data' => $model->load('ad', 'ad.cube', 'user_reporter')
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                'message' => 'Internal server error',
                'error' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile()
            ], 500);
        }
    }
}
