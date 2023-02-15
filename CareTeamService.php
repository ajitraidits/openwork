<?php

namespace App\Services\Api;

use App\Events\SetUpPasswordEvent;
use App\Helper;
use App\Models\Client\CareTeam;
use App\Models\Client\CareTeamMember;
use App\Models\Client\Client;
use App\Models\Client\Site\Site;
use App\Models\Dashboard\Timezone;
use App\Models\Role\Role;
use App\Models\Staff\Staff;
use App\Models\User\User;
use App\Transformers\CareTeamTransformer\CareTeamListTransformer;
use App\Transformers\CareTeamTransformer\CareTeamTransformer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\Log\ChangeLog;
use App\Models\Program\Program;
use Illuminate\Support\Facades\Auth;
use App\Models\Client\AssignProgram\AssignProgram;

class CareTeamService
{

    public function careTeamCreate($request)
    {
        DB::beginTransaction();
        try {
            $siteId = Helper::tableName('App\Models\Client\Site\Site', $request->siteId);
            $clientId = Helper::tableName('App\Models\Client\Client', $request->clientId);
            $site = Site::find($siteId);
            $client = Client::find($clientId);
            if (!$site || !$client) {
                return response()->json(['message' => trans('messages.UUID_NOT_FOUND')], 404);
            }
            $input = $request->only('name');
            $other = [
                'createdBy' => Auth::id(),
                'udid' => Str::uuid()->toString(),
                'siteId' => $siteId,
                'clientId' => $clientId,
            ];
            $data = array_merge($input, $other);
            $careTeam = new CareTeam();
            $careTeam = $careTeam->storeData($data);
            if ($request->input('programs')) {
                $programData = [];
                $programArray = Program::whereIn('udid', $request->programs)->get('id');
                foreach ($programArray as $key => $program) {
                    $programData[$key] = ['entityType' => 'CareTeam', 'referenceId' => $careTeam->id, 'programId' => $program->id];
                }
                $AssignProgram = new AssignProgram();
                $AssignProgram = $AssignProgram->addData($programData);
                if (!$AssignProgram) {
                    return response()->json(['message' => trans('messages.INTERNAL_ERROR')], 500);
                }
            }
            if ($careTeam) {
                $setData = $this->addMember($request, $careTeam);
                if (!$setData) {
                    return response()->json(['message' => trans('messages.INTERNAL_ERROR')], 500);
                }
                DB::commit();
                return response()->json(['message' => trans('messages.CREATED_SUCCESS')]);
            }
            return response()->json(['message' => trans('messages.INTERNAL_ERROR')], 500);

        } catch (\Exception $e) {
            DB::rollBack();
            throw new \RuntimeException($e);
        }
    }

    public function careTeamList($request, $id)
    {
        try {
            $CareTeam = CareTeam::with('site');
            if (!$id) {
                $CareTeam = $CareTeam->orderByDesc('id')->paginate(env('PER_PAGE', 20));
                return fractal()->collection($CareTeam)->transformWith(new CareTeamListTransformer())->toArray();
            }
            $careTeamId = Helper::tableName('App\Models\Client\CareTeam', $id);
            $cT = CareTeam::where(['id' => $careTeamId])->first();
            if (!$cT) {
                return response()->json(['message' => trans('messages.UUID_NOT_FOUND')], 404);
            }
            $CareTeam = $CareTeam->where(['id' => $cT->id])->first();
            return fractal()->item($CareTeam)->transformWith(new CareTeamTransformer())->toArray();
        } catch (\Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function careTeamListBySiteId($request, $id)
    {
        try {
            $siteId = Helper::tableName('App\Models\Client\Site\Site', $id);
            $site = Site::find($siteId);
            if (!$site) {
                return response()->json(['message' => trans('messages.UUID_NOT_FOUND')], 404);
            }
            $CareTeam = CareTeam::where(['siteId' => $site->id]);
            $CareTeam = $CareTeam->orderByDesc('id')->paginate(env('PER_PAGE', 20));
            return fractal()->collection($CareTeam)->transformWith(new CareTeamTransformer())->toArray();
        } catch (\Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function careTeamListByClientId($request, $id)
    {
        try {
            $clientId = Helper::tableName('App\Models\Client\Client', $id);
            if (!$clientId) {
                return response()->json(['message' => trans('messages.UUID_NOT_FOUND')], 404);
            }
            $CareTeam = CareTeam::with('head')->where(['clientId' => $clientId]);
            $CareTeam = $CareTeam->orderByDesc('id')->paginate(env('PER_PAGE', 20));
            return fractal()->collection($CareTeam)->transformWith(new CareTeamListTransformer())->toArray();
        } catch (\Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function careTeamUpdate($request, $id)
    {
        DB::beginTransaction();
        try {
            $siteId = Helper::tableName('App\Models\Client\Site\Site', $request->siteId);
            $data = CareTeam::where(['udid' => $id])->first();
            $dataCareTeam = CareTeam::where(['udid' => $id])->first();
            if (!$data) {
                return response()->json(['message' => trans('messages.UUID_NOT_FOUND')], 404);
            }
            $reqData = $this->RequestInputs($request);
            $other = [
                'siteId' => $siteId,
            ];
            $reqData = array_merge($reqData, $other);
            if (isset($request->programs)) {
                AssignProgram::where(['referenceId' => $data->id, 'entityType' => 'CareTeam'])->delete();
                $programData = [];
                $programArray = Program::whereIn('udid', $request->programs)->get('id');
                foreach ($programArray as $key => $program) {
                    $programData[$key] = ['entityType' => 'CareTeam', 'referenceId' => $data->id, 'programId' => $program->id];
                }
                $AssignProgram = new AssignProgram();
                $AssignProgram = $AssignProgram->addData($programData);
                if (!$AssignProgram) {
                    return response()->json(['message' => trans('messages.INTERNAL_ERROR')], 500);
                }
            }
            $CareTeam = new CareTeam();
            $CareTeam = $CareTeam->updateCareTeam($id, $reqData);
            if (!$CareTeam) {
                return response()->json(['message' => trans('messages.INTERNAL_ERROR')], 500);
            }

            $setData = $this->addMember($request, $dataCareTeam);
            if (!$setData) {
                return response()->json(['message' => trans('messages.INTERNAL_ERROR')], 500);
            }
            DB::commit();
            return response()->json(['message' => trans('messages.DATA_UPDATED')]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \RuntimeException($e);
        }
    }

    public function careTeamDelete($request, $id): ?\Illuminate\Http\JsonResponse
    {
        try {
            $input = $this->deleteInputs();
            $CareTeam = CareTeam::where(['udid' => $id])->first();
            if (!$CareTeam) {
                return response()->json(['message' => trans('messages.UUID_NOT_FOUND')], 404);
            }
            $careId = $CareTeam->id;
            $changeLog = [
                'udid' => Str::uuid()->toString(), 'table' => 'care_teams', 'tableId' => $CareTeam->id,
                'value' => json_encode($input), 'type' => 'deleted', 'ip' => request()->ip(), 'createdBy' => Auth::id(),
            ];
            $log = new ChangeLog();
            $log->makeLog($changeLog);
            $CareTeam = new CareTeam();
            $CareTeamMember = new CareTeamMember();
            AssignProgram::where(['referenceId' => $CareTeam->id, 'entityType' => 'CareTeam'])->delete();
            $CareTeam = $CareTeam->dataSoftDelete($id, $input);
            $CareTeamMember->dataSoftDeleteByCareTeamId($careId, $input);
            if (!$CareTeam) {
                return response()->json(['message' => trans('messages.INTERNAL_ERROR')], 500);
            }
            return response()->json(['message' => trans('messages.deletedSuccesfully')]);
        } catch (\Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function deleteInputs(): array
    {
        return ['isActive' => 0, 'isDelete' => 1, 'deletedBy' => Auth::id(), 'deletedAt' => Carbon::now()];
    }

    public function RequestInputs($request): array
    {
        $data = array();
        if ($request->name) {
            $data['name'] = $request->name;
        }
        $data['updatedBy'] = Auth::id();
        return $data;
    }

    public function addMember($request, $dataCareTeam)
    {
        if ($request->teamHeadId !== 0) {
            $staff = Staff::where('udid', $request->teamHeadId)->first();
            $userId = User::where('id', $staff->userId)->first();
            if (!$userId) {
                return response()->json(['message' => trans('messages.UUID_NOT_FOUND')], 404);
            }
            $input = $this->deleteInputs();
            $checkExist = CareTeamMember::where(['careTeamId' => $dataCareTeam->id, 'contactId' => $userId->id])->first();
            if ($checkExist) {
                CareTeamMember::where(['careTeamId' => $dataCareTeam->id])->update(['isHead' => 0]);
                CareTeamMember::where(['careTeamId' => $dataCareTeam->id, 'contactId' => $userId->id])->update(['isHead' => 1]);
                return $checkExist;
            }
            CareTeamMember::where(['careTeamId' => $dataCareTeam->id, 'isHead' => 1])->update($input);
            $data = [
                'createdBy' => Auth::id(),
                'udid' => Str::uuid()->toString(),
                'contactId' => $userId->id,
                'careTeamId' => $dataCareTeam->id,
                'isHead' => 1
            ];
            $careTeamMember = new CareTeamMember();
            $careTeamMember = $careTeamMember->storeData($data);
            if (!$careTeamMember) {
                return response()->json(['message' => trans('messages.INTERNAL_ERROR')], 500);
            }
            return $careTeamMember;
        } else {
            if ($request->member) {
                $timeZone = Timezone::where('udid', $request->member['timeZoneId'])->first();
                $roleDetail = Role::where('udid', $request->member['roleId'])->first();
                $password = Str::random("10");
                $user = [
                    'udid' => Str::uuid()->toString(),
                    'email' => $request->member['email'],
                    'password' => Hash::make($password),
                    'emailVerify' => 1,
                    'createdBy' => Auth::id(),
                    'roleId' => @$roleDetail->id,
                    'timeZoneId' => @$timeZone->id,
                ];
                $data = new User();
                $data = $data->userAdd($user);
                if (!$data) {
                    return response()->json(['message' => trans('messages.INTERNAL_ERROR')], 500);
                }
                // $data = User::create($user);
                $client = Helper::tableName('App\Models\Client\Client', $request->clientId);
                $input = [
                    'firstName' => $request->member['firstName'], 'title' => $request->member['title'], 'userId' => $data->id,
                    'middleName' => $request->member['middleName'], 'lastName' => $request->member['lastName'], 'clientId' => $client,
                    'phoneNumber' => $request->member['phoneNumber'], 'udid' => Str::uuid()->toString()
                ];
                $people = new Staff();
                $people = $people->peopleAdd($input);
                //set password event for mail
                if (!$people) {
                    return response()->json(['message' => trans('messages.INTERNAL_ERROR')], 500);
                }
                event(new SetUpPasswordEvent($request, $data));
                if ($data->id) {
                    $data = [
                        'createdBy' => Auth::id(),
                        'udid' => Str::uuid()->toString(),
                        'contactId' => $data->id,
                        'careTeamId' => $dataCareTeam->id,
                        'isHead' => 1
                    ];
                    CareTeamMember::where(['careTeamId' => $dataCareTeam->id])->update(['isHead' => 0]);
                    $careTeamMember = new CareTeamMember();
                    $careTeamMember = $careTeamMember->storeData($data);
                    if (!$careTeamMember) {
                        return response()->json(['message' => trans('messages.INTERNAL_ERROR')], 500);
                    }
                    return $careTeamMember;
                }
            }
        }

    }
}
