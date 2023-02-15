<?php

namespace App\Services\Api;


use Exception;
use App\Helper;
use Carbon\Carbon;
use App\Models\Flag\Flag;
use App\Models\Note\Note;
use App\Models\Staff\Staff;
use Illuminate\Support\Str;
use App\Models\Log\ChangeLog;
use App\Models\Patient\Patient;
use App\Library\ErrorLogGenerator;
use App\Models\Dashboard\Timezone;
use Illuminate\Support\Facades\DB;
use App\Models\Patient\PatientStaff;
use Illuminate\Support\Facades\Auth;
use App\Models\Appointment\Appointment;
use App\Models\Patient\PatientTimeLine;
use App\Models\Communication\CallRecord;
use App\Models\Notification\Notification;
use App\Models\Communication\Communication;
use App\Models\Patient\PatientFamilyMember;
use App\Models\Communication\CommunicationCallRecord;
use App\Transformers\Appointment\AppointmentTransformer;
use App\Transformers\Appointment\AppointmentDataTransformer;
use App\Transformers\Appointment\AppointmentListTransformer;
use App\Transformers\Appointment\AppointmentSearchTransformer;
use App\Events\NotificationEvent;

class CareTeamService
{

    public function convertTimeToUTC($timestamp = NULL, $selectedZone)
    {
        try {
            if ($timestamp) {
                $date = Carbon::createFromFormat('Y-m-d H:i:s', $timestamp, $selectedZone);
                $date->setTimezone('UTC');
                return $date;
            }
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    // Add Appointment
    public function addAppointment($request, $id)
    {
        try {
            $timeZoneId = '';
            $startDateTime = Helper::dateOnly($request->input('startDate'));
            $startDateTime = $startDateTime . ' ' . $request->input('startTime');
            if ($request->input('timezoneId')) {
                $timezone = Timezone::where("udid", $request->timezoneId)->first();
                if (isset($timezone->id)) {
                    $timeZoneId = $timezone->id;
                    $selectedZone = $timezone->timeZone;
                } else {
                    $response = ['message' => "timezone invalid!"];
                    return response()->json($response, 400);
                }
            }

            if (Carbon::parse($startDateTime) < Carbon::today()->toDateString()) {
                return response()->json(['startDate' => array(trans('messages.datePrevious'))], 422);
            } else {
                $startDateTime = date('Y-m-d H:i:s', strtotime($startDateTime));
                if ($request->input('timezoneId')) {
                    $startDateTimes = $this->convertTimeToUTC($startDateTime, $selectedZone);
                } else {
                    $startDateTimes = $startDateTime;
                }
            }
            $provider = Helper::providerId();
            $providerLocation = Helper::providerLocationId();
            $entityType = Helper::entityType();
            $input = [
                'udid' => Str::uuid()->toString(),
                'appointmentTypeId' => $request->appointmentTypeId,
                'startDateTime' => $startDateTimes,
                'durationId' => $request->durationId,
                'createdBy' => Auth::user()->id,
                'providerId' => $provider,
                'providerLocationId' => $providerLocation,
                'entityType' => $entityType,
                'timezoneId' => $timeZoneId,
            ];

            if (Auth::user()->patient) {
                $patientData = Patient::where('userId', Auth::user()->id)->first();
                $staff = Helper::entity('staff', $request->staffId);
                $patient = Auth::user()->id;
                $entity = [
                    'staffId' => $staff,
                    'statusId' => 144,
                    'patientId' => $patientData->id,
                ];
            } elseif (auth()->user()->staff) {
                $staff = Helper::entity('staff', $request->staffId);
                $patient = Helper::entity('patient', $request->patientId);
                $entity = [
                    'staffId' => $staff,
                    'statusId' => 155,
                    'patientId' => $patient,
                ];
            } elseif ($id) {
                $familyMember = PatientFamilyMember::where([['userId', auth()->user()->id], ['isPrimary', 1]])->exists();
                $patient = Helper::entity('patient', $id);
                if ($familyMember == true) {
                    $staff = Helper::entity('staff', $request->staffId);
                    $entity = [
                        'staffId' => $staff,
                        'statusId' => 144,
                        'patientId' => $patient,
                    ];
                } else {
                    return response()->json(['message' => trans('messages.unauthenticated')], 401);
                }
            }
            $data = array_merge($entity, $input);
            $existence = DB::select(
                "CALL appointmentExist('" . $staff . "','" . $startDateTimes . "')",
            );
            $existencePatient = DB::select(
                "CALL appointmentExistForPatient('" . $patient . "','" . $startDateTimes . "')",
            );

            if ($existence[0]->isExist == false && $existencePatient[0]->isExist == false) {
                $staff = PatientStaff::where([['staffId', $data['staffId']], ['patientId', $data['patientId']]])->first();
                if (empty($staff)) {
                    if (!Helper::haveAccessAction($staff, 62) && !Helper::haveAccessAction($staff, 37)) {
                        return response()->json(['staffId' => array(trans('messages.staffUnauthrized'))], 422);
                    }
                }
                $appointment = Appointment::create($data);
                $changeLog = [
                    'udid' => Str::uuid()->toString(), 'table' => 'appointments', 'tableId' => $appointment->id, 'entityType' => $entityType,
                    'value' => json_encode($data), 'type' => 'created', 'ip' => request()->ip(), 'createdBy' => Auth::id(), 'providerId' => $provider, 'providerLocationId' => $providerLocation
                ];
                ChangeLog::create($changeLog);

                if (Auth::user()->roleId == 4) {
                    $staff = Staff::where('udid', $request->staffId)->first();
                    $userId = $staff->userId;
                    $patient = Patient::where('userId', Auth::user()->id)->first();
                    $firstName = $patient->firstName;
                    $lastName = $patient->lastName;
                    $notification = Notification::create([
                        'body' => 'There is New Appointment for You With' . ' ' . $firstName . ' ' . $lastName,
                        'title' => 'New Appointment',
                        'userId' => $userId,
                        'isSent' => 0,
                        'entity' => 'Appointment',
                        'referenceId' => $appointment->id,
                        'createdBy' => Auth::id(),
                        'providerId' => $provider,
                        'providerLocationId' => $providerLocation,
                        'entityType' => $entityType,
                    ]);

                    event(new NotificationEvent($notification));
                    $notificationUpdate = ['isSent' => '1', 'providerId' => $provider, 'providerLocationId' => $providerLocation, 'updatedBy' => Auth::id()];
                    Notification::where('id', $notification->id)->update($notificationUpdate);
                } else {
                    $patient = Patient::where('udid', $request->patientId)->first();
                    $userId = $patient->userId;
                    $staff = Staff::where('udid', $request->staffId)->first();
                    $firstName = $staff->firstName;
                    $lastName = $staff->lastName;
                    $notification = Notification::create([
                        'body' => 'There is New Appointment for You With' . ' ' . $firstName . ' ' . $lastName,
                        'title' => 'New Appointment',
                        'userId' => $userId,
                        'isSent' => 0,
                        'entity' => 'Appointment',
                        'referenceId' => $appointment->id,
                        'createdBy' => Auth::id(),
                        'providerId' => $provider,
                        'providerLocationId' => $providerLocation,
                        'entityType' => $entityType,
                    ]);

                    event(new NotificationEvent($notification));
                    $notificationUpdate = ['isSent' => '1', 'providerId' => $provider, 'providerLocationId' => $providerLocation, 'updatedBy' => Auth::id()];
                    Notification::where('id', $notification->id)->update($notificationUpdate);

                    if (Auth::user()->staff->id != $data['staffId']) {
                        $staff = Staff::where('id', $data['staffId'])->first();

                        $userId = $staff->userId;
                        $patient = Patient::where('id', $data['patientId'])->first();
                        $firstName = $patient->firstName;
                        $lastName = $patient->lastName;
                        $notification = Notification::create([
                            'body' => 'There is new appointment for you with' . ' ' . $firstName . ' ' . $lastName,
                            'title' => 'New Appointment',
                            'userId' => $userId,
                            'isSent' => 0,
                            'entity' => 'Appointment',
                            'referenceId' => $appointment->id,
                            'createdBy' => Auth::id(),
                            'providerId' => $provider,
                            'providerLocationId' => $providerLocation,
                            'entityType' => $entityType,
                        ]);

                        event(new NotificationEvent($notification));
                        $notificationUpdate = ['isSent' => '1', 'providerId' => $provider, 'providerLocationId' => $providerLocation, 'updatedBy' => Auth::id()];
                        Notification::where('id', $notification->id)->update($notificationUpdate);

                    } else {
                        $patient = Patient::where('udid', $request->patientId)->first();
                        $userId = $patient->userId;
                        $staff = Staff::where('udid', $request->staffId)->first();
                        $firstName = $staff->firstName;
                        $lastName = $staff->lastName;
                        $notification = Notification::create([
                            'body' => 'There is new appointment for you with' . ' ' . $firstName . ' ' . $lastName,
                            'title' => 'New Appointment',
                            'userId' => $userId,
                            'isSent' => 0,
                            'entity' => 'Appointment',
                            'referenceId' => $appointment->id,
                            'createdBy' => Auth::id(),
                            'providerId' => $provider
                        ]);

                        event(new NotificationEvent($notification));
                        $notificationUpdate = ['isSent' => '1', 'providerId' => $provider, 'providerLocationId' => $providerLocation, 'updatedBy' => Auth::id()];
                        Notification::where('id', $notification->id)->update($notificationUpdate);

                        if (Auth::user()->staff->id != $data['staffId']) {
                            $staff = Staff::where('id', $data['staffId'])->first();
                            $userId = $staff->userId;
                            $patient = Patient::where('id', $data['patientId'])->first();
                            $firstName = $patient->firstName;
                            $lastName = $patient->lastName;
                            $notification = Notification::create([
                                'body' => 'There is new appointment for you with' . ' ' . $firstName . ' ' . $lastName,
                                'title' => 'New Appointment',
                                'userId' => $userId,
                                'isSent' => 0,
                                'entity' => 'Appointment',
                                'referenceId' => $appointment->id,
                                'createdBy' => Auth::id(),
                                'providerId' => $provider
                            ]);

                            event(new NotificationEvent($notification));
                            $notificationUpdate = ['isSent' => '1', 'providerId' => $provider, 'providerLocationId' => $providerLocation, 'updatedBy' => Auth::id()];
                            Notification::where('id', $notification->id)->update($notificationUpdate);
                        }
                    }
                    $flag = Flag::where('udid', $request->input('flag'))->first();
                    $note = [
                        'createdBy' => Auth::id(), 'note' => $request->input('note'), 'udid' => Str::uuid()->toString(), 'entityType' => 'appointment',
                        'referenceId' => $appointment->id, 'flagId' => $flag->id, 'providerId' => $provider
                    ];
                    $noteData = Note::create($note);
                    if (auth()->user()->roleId == 4) {
                        $userInput = Patient::where('id', auth()->user()->patient->id)->first();
                    } else {
                        $userInput = Staff::where('id', auth()->user()->staff->id)->first();
                    }
                    $timeLine = [
                        'patientId' => $patient->id, 'heading' => 'Appointment Note Added', 'title' => $request->input('note') . ' ' . '<b>By' . ' ' . $userInput->lastName . ',' . ' ' . $userInput->firstName . '</b>', 'type' => 6,
                        'createdBy' => Auth::id(), 'udid' => Str::uuid()->toString()
                    ];
                    PatientTimeLine::create($timeLine);
                    $changeLog = [
                        'udid' => Str::uuid()->toString(), 'table' => 'notes', 'tableId' => $noteData->id,
                        'value' => json_encode($note), 'type' => 'created', 'ip' => request()->ip(), 'createdBy' => Auth::id(), 'providerId' => $provider
                    ];
                    ChangeLog::create($changeLog);
                    $patientData = Patient::where('id', $data['patientId'])->first();
                    $staffData = Staff::where('id', $data['staffId'])->first();
                    $timeLine = [
                        'patientId' => $patientData->id, 'heading' => 'Appointment', 'title' => 'Appointment for' . ' ' . $patientData->lastName . ',' . ' ' . $patientData->firstName . ' ' . $patientData->middleName . ' ' . 'Added with' . ' ' . $staffData->firstName . ' ' . $staffData->lastName, 'type' => 2,
                        'createdBy' => Auth::id(), 'udid' => Str::uuid()->toString(), 'providerId' => $provider
                    ];
                    $timeLineData = PatientTimeLine::create($timeLine);
                    $changeLog = [
                        'udid' => Str::uuid()->toString(), 'table' => 'patientTimelines', 'tableId' => $timeLineData->id,
                        'value' => json_encode($timeLine), 'type' => 'created', 'ip' => request()->ip(), 'createdBy' => Auth::id(), 'providerId' => $provider
                    ];
                    ChangeLog::create($changeLog);
                    if (Auth::user()->roleId == 4) {
                        return response()->json(['message' => trans('messages.add_appointment')], 200);
                    } else {
                        return response()->json(['message' => trans('messages.createdSuccesfully')], 200);
                    }
                }
                $flag = Flag::where('udid', $request->input('flag'))->first();
                $note = [
                    'createdBy' => Auth::id(), 'note' => $request->input('note'), 'udid' => Str::uuid()->toString(), 'entityType' => 'appointment',
                    'referenceId' => $appointment->id, 'flagId' => $flag->id, 'providerId' => $provider, 'providerLocationId' => $providerLocation,
                ];
                $noteData = Note::create($note);
                if (auth()->user()->roleId == 4) {
                    $userInput = Patient::where('id', auth()->user()->patient->id)->first();
                } else {
                    $userInput = Staff::where('id', auth()->user()->staff->id)->first();
                }
                $timeLine = [
                    'patientId' => $patient->id, 'heading' => 'Appointment Note Added', 'title' => $request->input('note') . ' ' . '<b>By' . ' ' . $userInput->lastName . ',' . ' ' . $userInput->firstName . '</b>', 'type' => 6,
                    'createdBy' => Auth::id(), 'udid' => Str::uuid()->toString(), 'providerId' => $provider, 'providerLocationId' => $providerLocation, 'entityType' => $entityType
                ];
                PatientTimeLine::create($timeLine);
                $changeLog = [
                    'udid' => Str::uuid()->toString(), 'table' => 'notes', 'tableId' => $noteData->id, 'value' => json_encode($note), 'type' => 'created',
                    'ip' => request()->ip(), 'createdBy' => Auth::id(), 'providerId' => $provider, 'providerLocationId' => $providerLocation, 'entityType' => $entityType
                ];
                ChangeLog::create($changeLog);
                $patientData = Patient::where('id', $data['patientId'])->first();
                $staffData = Staff::where('id', $data['staffId'])->first();
                $timeLine = [
                    'patientId' => $patientData->id, 'heading' => 'Appointment', 'title' => 'Appointment for' . ' ' . $patientData->lastName . ',' . ' ' . $patientData->firstName . ' ' . $patientData->middleName . ' ' . 'Added with' . ' ' . $staffData->firstName . ' ' . $staffData->lastName, 'type' => 2,
                    'createdBy' => Auth::id(), 'udid' => Str::uuid()->toString(), 'providerId' => $provider, 'providerLocationId' => $providerLocation, 'entityType' => $entityType
                ];
                $timeLineData = PatientTimeLine::create($timeLine);
                $changeLog = [
                    'udid' => Str::uuid()->toString(), 'table' => 'patientTimelines', 'tableId' => $timeLineData->id, 'value' => json_encode($timeLine), 'entityType' => $entityType,
                    'type' => 'created', 'ip' => request()->ip(), 'createdBy' => Auth::id(), 'providerId' => $provider, 'providerLocationId' => $providerLocation
                ];
                ChangeLog::create($changeLog);
                if (Auth::user()->roleId == 4) {
                    return response()->json(['message' => trans('messages.add_appointment')], 200);
                } else {
                    return response()->json(['message' => trans('messages.createdSuccesfully')], 200);
                }
            } else {
                if ($existence[0]->isExist == true) {
                    return response()->json(['staffId' => array(trans('messages.StaffAppointmentExists'))], 422);
                } else {
                    return response()->json(['patientId' => array(trans('messages.PatientAppointmentExists'))], 422);
                }
            }
        } catch (Exception $e) {
            if (isset(auth()->user()->id)) {
                $userId = auth()->user()->id;
            } else {
                $userId = "";
            }
            ErrorLogGenerator::createLog($request, $e, $userId);
            throw new \RuntimeException($e);
        }
    }

    // List Appointment
    public function appointmentList($request, $id)
    {
        try {
            $data = Appointment::select('appointments.*')
                ->with('status')->whereRaw('(conferenceId IS NOT NULL || startDateTime >="' . Carbon::today() . '")');

            $data->leftJoin('providers', 'providers.id', '=', 'appointments.providerId')->where('providers.isActive', 1)->whereNull('providers.deletedAt');
            $data->leftJoin('programs', 'programs.id', '=', 'appointments.programId')->where('programs.isActive', 1)->whereNull('programs.deletedAt');

            $data->leftJoin('providerLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocations.id')->where('appointments.entityType', '=', 'Country');
            })->whereNull('providerLocations.deletedAt');

            $data->leftJoin('providerLocationStates', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationStates.id')->where('appointments.entityType', '=', 'State');
            })->whereNull('providerLocationStates.deletedAt');

            $data->leftJoin('providerLocationCities', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationCities.id')->where('appointments.entityType', '=', 'City');
            })->whereNull('providerLocationCities.deletedAt');

            $data->leftJoin('subLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'subLocations.id')->where('appointments.entityType', '=', 'subLocation');
            })->whereNull('subLocations.deletedAt');

            if (request()->header('providerId')) {
                $provider = Helper::providerId();
                $data->where('appointments.providerId', $provider);
            }
            if (request()->header('providerLocationId')) {
                $providerLocation = Helper::providerLocationId();
                if (request()->header('entityType') == 'Country') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'Country']]);
                }
                if (request()->header('entityType') == 'State') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'State']]);
                }
                if (request()->header('entityType') == 'City') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'City']]);
                }
                if (request()->header('entityType') == 'subLocation') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'subLocation']]);
                }
            }
            if (request()->header('programId')) {
                $program = Helper::programId();
                $entityType = Helper::entityType();
                $data->where([['appointments.programId', $program], ['appointments.entityType', $entityType]]);
            }
            if (!$id) {
                $data = $data->where('appointments.patientId', auth()->user()->patient->id)->orderBy('appointments.startDateTime', 'ASC')->get();
                $results = Helper::dateGroup($data, 'startDateTime');
                return fractal()->collection($results)->transformWith(new AppointmentListTransformer())->toArray();
            } elseif ($id) {
                $patient = Helper::entity('patient', $id);
                $notAccess = Helper::haveAccess($patient);
                if (!$notAccess) {
                    if (auth()->user()->roleId == 3) {
                        if (Helper::haveAccessAction(null, 62) && Helper::haveAccessAction(null, 37)) {
                            $data;
                        } else {
                            $data->where([['appointments.staffId', auth()->user()->staff->id], ['appointments.patientId', $patient]]);
                        }
                    } else {
                        $data->where('appointments.patientId', $patient);
                    }
                    $data = $data->orderBy('appointments.startDateTime', 'ASC')->get();
                    $results = Helper::dateGroup($data, 'startDateTime');
                    return fractal()->collection($results)->transformWith(new AppointmentListTransformer())->toArray();
                } else {
                    return $notAccess;
                }
            }
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    // Detail Appointment
    public function appointmentDetail($request, $id)
    {
        try {
            $data = Appointment::with('status')->select('appointments.*')->where('appointments.id', $id);
            $data->leftJoin('providers', 'providers.id', '=', 'appointments.providerId')->where('providers.isActive', 1)->whereNull('providers.deletedAt');
            $data->leftJoin('programs', 'programs.id', '=', 'appointments.programId')->where('programs.isActive', 1)->whereNull('programs.deletedAt');

            $data->leftJoin('providerLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocations.id')->where('appointments.entityType', '=', 'Country');
            })->whereNull('providerLocations.deletedAt');

            $data->leftJoin('providerLocationStates', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationStates.id')->where('appointments.entityType', '=', 'State');
            })->whereNull('providerLocationStates.deletedAt');

            $data->leftJoin('providerLocationCities', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationCities.id')->where('appointments.entityType', '=', 'City');
            })->whereNull('providerLocationCities.deletedAt');

            $data->leftJoin('subLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'subLocations.id')->where('appointments.entityType', '=', 'subLocation');
            })->whereNull('subLocations.deletedAt');

            if (request()->header('providerId')) {
                $provider = Helper::providerId();
                $data->where('appointments.providerId', $provider);
            }
            if (request()->header('providerLocationId')) {
                $providerLocation = Helper::providerLocationId();
                if (request()->header('entityType') == 'Country') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'Country']]);
                }
                if (request()->header('entityType') == 'State') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'State']]);
                }
                if (request()->header('entityType') == 'City') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'City']]);
                }
                if (request()->header('entityType') == 'subLocation') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'subLocation']]);
                }
            }
            if (request()->header('programId')) {
                $program = Helper::programId();
                $entityType = Helper::entityType();
                $data->where([['appointments.programId', $program], ['appointments.entityType', $entityType]]);
            }
            $data = $data->first();
            return fractal()->item($data)->transformWith(new AppointmentTransformer())->toArray();
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    // New Appointments
    public function newAppointments($request)
    {
        try {
            $data = Appointment::select('appointments.*')->with('patient', 'staff', 'appointmentType', 'duration')->whereHas('patient', function ($query) {
                $query->whereNull('patients.deletedAt');
            })->whereRaw('conferenceId != "" OR conferenceId IS NOT NULL');

            $data->leftJoin('providers', 'providers.id', '=', 'appointments.providerId')->where('providers.isActive', 1)->whereNull('providers.deletedAt');
            $data->leftJoin('programs', 'programs.id', '=', 'appointments.programId')->where('programs.isActive', 1)->whereNull('programs.deletedAt');

            $data->leftJoin('providerLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocations.id')->where('appointments.entityType', '=', 'Country');
            })->whereNull('providerLocations.deletedAt');

            $data->leftJoin('providerLocationStates', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationStates.id')->where('appointments.entityType', '=', 'State');
            })->whereNull('providerLocationStates.deletedAt');

            $data->leftJoin('providerLocationCities', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationCities.id')->where('appointments.entityType', '=', 'City');
            })->whereNull('providerLocationCities.deletedAt');

            $data->leftJoin('subLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'subLocations.id')->where('appointments.entityType', '=', 'subLocation');
            })->whereNull('subLocations.deletedAt');

            if (request()->header('providerId')) {
                $provider = Helper::providerId();
                $data->where('appointments.providerId', $provider);
            }
            if (request()->header('providerLocationId')) {
                $providerLocation = Helper::providerLocationId();
                if (request()->header('entityType') == 'Country') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'Country']]);
                }
                if (request()->header('entityType') == 'State') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'State']]);
                }
                if (request()->header('entityType') == 'City') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'City']]);
                }
                if (request()->header('entityType') == 'subLocation') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'subLocation']]);
                }
            }
            if (request()->header('programId')) {
                $program = Helper::programId();
                $entityType = Helper::entityType();
                $data->where([['appointments.programId', $program], ['appointments.entityType', $entityType]]);
            }

            if (auth()->user()->roleId == 3) {
                if (Helper::haveAccessAction(null, 62) && Helper::haveAccessAction(null, 37)) {
                    $data;
                } else {
                    $data->where('appointments.staffId', auth()->user()->staff->id);
                }
            }
            $data = $data->orderBy('appointments.startDateTime', 'ASC')->take(3)->get();
            return fractal()->collection($data)->transformWith(new AppointmentTransformer())->toArray();
        } catch (Exception $e) {
            if (isset(auth()->user()->id)) {
                $userId = auth()->user()->id;
            } else {
                $userId = "";
            }
            ErrorLogGenerator::createLog($request, $e, $userId);
            throw new \RuntimeException($e);
        }
    }

    // Today Appointment
    public function todayAppointment($request, $id)
    {
        try {
            if ($id) {
                $patient = Helper::entity('patient', $id);
            } else {
                if (isset(auth()->user()->patient->id)) {
                    $patient = auth()->user()->patient->id;
                } else {
                    $patient = "";
                }
            }

            $currentDate = Carbon::today();
            $dateF = $currentDate->format('Y-m-d');
            $data = Appointment::select('appointments.*')
                ->with('patient', 'staff', 'appointmentType', 'duration')
                ->where('appointments.startDateTime', 'LIKE', "%" . $dateF . "%");

            $data->leftJoin('providers', 'providers.id', '=', 'appointments.providerId')->where('providers.isActive', 1)->whereNull('providers.deletedAt');
            $data->leftJoin('programs', 'programs.id', '=', 'appointments.programId')->where('programs.isActive', 1)->whereNull('programs.deletedAt');

            $data->leftJoin('providerLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocations.id')->where('appointments.entityType', '=', 'Country');
            })->whereNull('providerLocations.deletedAt');

            $data->leftJoin('providerLocationStates', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationStates.id')->where('appointments.entityType', '=', 'State');
            })->whereNull('providerLocationStates.deletedAt');

            $data->leftJoin('providerLocationCities', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationCities.id')->where('appointments.entityType', '=', 'City');
            })->whereNull('providerLocationCities.deletedAt');

            $data->leftJoin('subLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'subLocations.id')->where('appointments.entityType', '=', 'subLocation');
            })->whereNull('subLocations.deletedAt');

            if (request()->header('providerId')) {
                $provider = Helper::providerId();
                $data->where('appointments.providerId', $provider);
            }
            if (request()->header('providerLocationId')) {
                $providerLocation = Helper::providerLocationId();
                if (request()->header('entityType') == 'Country') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'Country']]);
                }
                if (request()->header('entityType') == 'State') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'State']]);
                }
                if (request()->header('entityType') == 'City') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'City']]);
                }
                if (request()->header('entityType') == 'subLocation') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'subLocation']]);
                }
            }
            if (request()->header('programId')) {
                $program = Helper::programId();
                $entityType = Helper::entityType();
                $data->where([['appointments.programId', $program], ['appointments.entityType', $entityType]]);
            }
            if (auth()->user()->patient) {
                $data->where([['appointments.patientId', auth()->user()->patient->id]]);
            }
            if (auth()->user()->staff) {
                $data->where([['appointments.staffId', auth()->user()->staff->id]]);
            }
            if ($id) {
                $data->where([['appointments.patientId', $patient]]);
            }
            if (auth()->user()->roleId == 6) {
                $notAccess = Helper::haveAccess($patient);
                if (!$notAccess) {
                    $familyMember = PatientFamilyMember::where([['userId', auth()->user()->id], ['patientId', $patient]])->exists();
                    if ($familyMember == false) {
                        return response()->json(['message' => trans('messages.unauthenticated')], 401);
                    }
                } else {
                    return $notAccess;
                }
            }
            if (auth()->user()->roleId == 3) {
                if (!Helper::haveAccessAction(null, 62) && !Helper::haveAccessAction(null, 37)) {
                    $staff = PatientStaff::where([['staffId', auth()->user()->staff->id], ['patientId', $patient]])->exists();
                    if ($staff == false) {
                        return response()->json(['message' => trans('messages.unauthenticated')], 401);
                    }
                }
            }
            $data = $data->orderBy('appointments.startDateTime', 'ASC')->get();
            return fractal()->collection($data)->transformWith(new AppointmentDataTransformer())->toArray();
        } catch (Exception $e) {
            if (isset(auth()->user()->id)) {
                $userId = auth()->user()->id;
            } else {
                $userId = "";
            }
            ErrorLogGenerator::createLog($request, $e, $userId);
            throw new \RuntimeException($e);
        }
    }

    // Search Appointment
    public function appointmentSearch($request)
    {
        try {
            $staffIdx = '';
            $fromDate = time();
            $toDate = '';
            if (!empty($request->toDate)) {
                $toDateFormate = Helper::date($request->input('toDate'));
                $toDate = $toDateFormate;
            }
            if (!empty($request->fromDate)) {
                $fromDateFormate = Helper::date($request->input('fromDate'));
                $fromDate = $fromDateFormate;
            }
            $staffIdx = '';
            $staffs = '';
            if (!empty($request->staffId) && $request->staffId != 'undefined') {
                $staffs = explode(',', $request->staffId);
                $staff_array = array();
                foreach ($staffs as $staff) {
                    $staff_id = Helper::entity('staff', trim($staff));
                    array_push($staff_array, $staff_id);
                }
                $staffIdx = json_encode($staff_array);
            } else {
                if (auth()->user()->roleId == 3) {
                    $staffIdx = json_encode(array(auth()->user()->staff->id));
                }
            }

            if ($request->filter) {
                $statusId = $request->filter;
            } else {
                $statusId = '';
            }

            if (request()->header('providerId')) {
                $provider = Helper::providerId();
            } else {
                $provider = '';
            }
            if (request()->header('providerLocationId')) {
                $providerLocation = Helper::providerLocationId();
            } else {
                $providerLocation = '';
            }

            $data = DB::select(
                "CALL appointmentList('" . $fromDate . "','" . $toDate . "','" . $staffIdx . "','" . $statusId . "','" . $provider . "','" . $providerLocation . "')",
            );
            return fractal()->collection($data)->transformWith(new AppointmentSearchTransformer())->toArray();
        } catch (Exception $e) {
            if (isset(auth()->user()->id)) {
                $userId = auth()->user()->id;
            } else {
                $userId = "";
            }
            ErrorLogGenerator::createLog($request, $e, $userId);
            throw new \RuntimeException($e);
        }
    }

    // Conference Appointment List
    public function AppointmentConference($request)
    {
        try {
            /*if (auth()->user()->roleId == 3) {
                $data = Appointment::where('staffId', auth()->user()->staff->id)->whereRaw('conferenceId is not null')->get();
            } else {*/
            $data = Appointment::select('appointments.*')->whereRaw('conferenceId is not null');

            $data->leftJoin('providers', 'providers.id', '=', 'appointments.providerId')->where('providers.isActive', 1)->whereNull('providers.deletedAt');
            $data->leftJoin('programs', 'programs.id', '=', 'appointments.programId')->where('programs.isActive', 1)->whereNull('programs.deletedAt');

            $data->leftJoin('providerLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocations.id')->where('appointments.entityType', '=', 'Country');
            })->whereNull('providerLocations.deletedAt');

            $data->leftJoin('providerLocationStates', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationStates.id')->where('appointments.entityType', '=', 'State');
            })->whereNull('providerLocationStates.deletedAt');

            $data->leftJoin('providerLocationCities', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationCities.id')->where('appointments.entityType', '=', 'City');
            })->whereNull('providerLocationCities.deletedAt');

            $data->leftJoin('subLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'subLocations.id')->where('appointments.entityType', '=', 'subLocation');
            })->whereNull('subLocations.deletedAt');

            if (request()->header('providerId')) {
                $provider = Helper::providerId();
                $data->where('appointments.providerId', $provider);
            }
            if (request()->header('providerLocationId')) {
                $providerLocation = Helper::providerLocationId();
                if (request()->header('entityType') == 'Country') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'Country']]);
                }
                if (request()->header('entityType') == 'State') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'State']]);
                }
                if (request()->header('entityType') == 'City') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'City']]);
                }
                if (request()->header('entityType') == 'subLocation') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'subLocation']]);
                }
            }
            if (request()->header('programId')) {
                $program = Helper::programId();
                $entityType = Helper::entityType();
                $data->where([['appointments.programId', $program], ['appointments.entityType', $entityType]]);
            }
            $data = $data->get();
            /*}*/
            return fractal()->collection($data)->transformWith(new AppointmentDataTransformer())->toArray();
        } catch (Exception $e) {
            if (isset(auth()->user()->id)) {
                $userId = auth()->user()->id;
            } else {
                $userId = "";
            }
            ErrorLogGenerator::createLog($request, $e, $userId);
            throw new \RuntimeException($e);
        }
    }

    // Appointment List According to Conference Id
    public function AppointmentConferenceId($request, $id)
    {
        try {
            $data = Appointment::select('appointments.*')->where('appointments.conferenceId', $id);
            if (auth()->user()->roleId == 3) {
                if (!Helper::haveAccessAction(null, 62) && !Helper::haveAccessAction(null, 37)) {
                    $data->where('appointments.staffId', auth()->user()->staff->id);
                }
            }
            $data->leftJoin('providers', 'providers.id', '=', 'appointments.providerId')->where('providers.isActive', 1)->whereNull('providers.deletedAt');
            $data->leftJoin('programs', 'programs.id', '=', 'appointments.programId')->where('programs.isActive', 1)->whereNull('programs.deletedAt');

            $data->leftJoin('providerLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocations.id')->where('appointments.entityType', '=', 'Country');
            })->whereNull('providerLocations.deletedAt');

            $data->leftJoin('providerLocationStates', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationStates.id')->where('appointments.entityType', '=', 'State');
            })->whereNull('providerLocationStates.deletedAt');

            $data->leftJoin('providerLocationCities', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationCities.id')->where('appointments.entityType', '=', 'City');
            })->whereNull('providerLocationCities.deletedAt');

            $data->leftJoin('subLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'subLocations.id')->where('appointments.entityType', '=', 'subLocation');
            })->whereNull('subLocations.deletedAt');

            if (request()->header('providerId')) {
                $provider = Helper::providerId();
                $data->where('appointments.providerId', $provider);
            }
            if (request()->header('providerLocationId')) {
                $providerLocation = Helper::providerLocationId();
                if (request()->header('entityType') == 'Country') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'Country']]);
                }
                if (request()->header('entityType') == 'State') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'State']]);
                }
                if (request()->header('entityType') == 'City') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'City']]);
                }
                if (request()->header('entityType') == 'subLocation') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'subLocation']]);
                }
            }
            if (request()->header('programId')) {
                $program = Helper::programId();
                $entityType = Helper::entityType();
                $data->where([['appointments.programId', $program], ['appointments.entityType', $entityType]]);
            }
            $data = $data->get();
            return fractal()->collection($data)->transformWith(new AppointmentDataTransformer())->toArray();
        } catch (Exception $e) {
            if (isset(auth()->user()->id)) {
                $userId = auth()->user()->id;
            } else {
                $userId = "";
            }
            ErrorLogGenerator::createLog($request, $e, $userId);
            throw new \RuntimeException($e);
        }
    }

    // Update Appointment
    public function appointmentUpdate($request, $id)
    {
        try {
            $provider = Helper::providerId();
            $providerLocation = Helper::providerLocationId();
            $entityType = Helper::entityType();
            $appointment = Appointment::where([['patientId', auth()->user()->patient->id], ['udid', $id]])->first();
            $existence = DB::select(
                "CALL appointmentExist('" . $appointment['staffId'] . "','" . Helper::date($request->startDateTime) . "')",
            );
            foreach ($existence as $exists) {
                if ($exists->isExist == false) {
                    $input = ['updatedBy' => Auth::id(), 'startDateTime' => Helper::date($request->startDateTime), 'providerId' => $provider, 'providerLocationId' => $providerLocation];

                    if (isset($request->timezoneId) && !empty($request->timezoneId)) {
                        $timezone = Timezone::where("udid", $request->timezoneId)->first();
                        if (isset($timezone->id)) {
                            $input['timezoneId'] = $timezone->id;
                        }
                    }
                    Appointment::where([['patientId', auth()->user()->patient->id], ['udid', $id]])->update($input);
                    $app = Helper::tableName('App\Models\Appointment\Appointment', $id);
                    $changeLog = [
                        'udid' => Str::uuid()->toString(), 'table' => 'appointments', 'tableId' => $app, 'entityType' => $entityType,
                        'value' => json_encode($input), 'type' => 'updated', 'ip' => request()->ip(), 'createdBy' => Auth::id(), 'providerId' => $provider, 'providerLocationId' => $providerLocation
                    ];
                    ChangeLog::create($changeLog);
                    if (Auth::user()->roleId == 4) {
                        $app = Appointment::where('udid', $id)->first();
                        $userId = $app->staff->userId;
                        $firstName = $app->patient->firstName;
                        $lastName = $app->patient->lastName;
                    } else {
                        $app = Appointment::where('udid', $id)->first();
                        $userId = $app->patient->userId;
                        $firstName = $app->staff->firstName;
                        $lastName = $app->staff->lastName;
                    }
                    $notificationData = [
                        'body' => 'There is appointment reschedule for you with' . ' ' . $firstName . ' ' . $lastName,
                        'title' => 'Reschedule Appointment',
                        'userId' => $userId,
                        'isSent' => 0,
                        'entity' => 'Appointment',
                        'referenceId' => $app->id,
                        'createdBy' => Auth::id(),
                        'providerId' => $provider,
                        'providerLocationId' => $providerLocation
                    ];
                    $notification = Notification::create($notificationData);
                    $changeLog = [
                        'udid' => Str::uuid()->toString(), 'table' => 'notifications', 'tableId' => $notification->id, 'entityType' => $entityType,
                        'value' => json_encode($notificationData), 'type' => 'created', 'ip' => request()->ip(), 'createdBy' => Auth::id(), 'providerId' => $provider, 'providerLocationId' => $providerLocation
                    ];
                    ChangeLog::create($changeLog);
                    $data = Appointment::where([['patientId', auth()->user()->patient->id], ['udid', $id]])->orderBy('startDateTime', 'ASC')->first();
                    $message = ['message' => 'Appointment Rescheduled Successfully'];
                    $newData = fractal()->item($data)->transformWith(new AppointmentTransformer())->toArray();
                    return array_merge($message, $newData);
                } else {
                    return response()->json(['message' => 'Appointment already exist!'], 422);
                }
            }
        } catch (Exception $e) {
            if (isset(auth()->user()->id)) {
                $userId = auth()->user()->id;
            } else {
                $userId = "";
            }
            ErrorLogGenerator::createLog($request, $e, $userId);
            throw new \RuntimeException($e);
        }
    }

    // Cancle Appointment
    public function appointmentDelete($request, $id)
    {
        try {
            $provider = Helper::providerId();
            $providerLocation = Helper::providerLocationId();
            $entityType = Helper::entityType();
            if (!empty($request->cancellationNote)) {
                $input = ['cancellationNote' => $request->cancellationNote, 'deletedBy' => Auth::id(), 'isDelete' => 1, 'isActive' => 0, 'providerId' => $provider, 'providerLocationId' => $providerLocation];
            } else {
                $input = ['cancellationNote' => 'Time is Not Available', 'deletedBy' => Auth::id(), 'isDelete' => 1, 'isActive' => 0, 'providerId' => $provider, 'providerLocationId' => $providerLocation];
            }
            Appointment::where([['patientId', auth()->user()->patient->id], ['udid', $id]])->update($input);
            $app = Helper::tableName('App\Models\Appointment\Appointment', $id);
            $changeLog = [
                'udid' => Str::uuid()->toString(), 'table' => 'appointments', 'tableId' => $app, 'entityType' => $entityType,
                'value' => json_encode($input), 'type' => 'updated', 'ip' => request()->ip(), 'createdBy' => Auth::id(), 'providerId' => $provider, 'providerLocationId' => $providerLocation
            ];
            ChangeLog::create($changeLog);
            Appointment::where([['patientId', auth()->user()->patient->id], ['udid', $id], ['startDateTime', '>=', Carbon::now()->subMinutes(60)]])->delete();
            return response()->json(['message' => trans('messages.cancelSuccesfully')]);
        } catch (Exception $e) {
            if (isset(auth()->user()->id)) {
                $userId = auth()->user()->id;
            } else {
                $userId = "";
            }
            ErrorLogGenerator::createLog($request, $e, $userId);
            throw new \RuntimeException($e);
        }
    }

    // Appointment Call
    public function appointmentCalls($request)
    {
        try {
            $provider = Helper::providerId();
            $providerLocation = Helper::providerLocationId();
            $entityType = Helper::entityType();
            $patientId = Helper::tableName('App\Models\Patient\Patient', $request->input('patientId'));
            $patientIdx = Patient::where('id', $patientId)->first();
            $appointmentExists = Appointment::where([['staffId', auth()->user()->staff->id], ['patientId', $patientId]])->whereNotNull('conferenceId')->exists();
            if ($appointmentExists) {
                $appointment = Appointment::where([['staffId', auth()->user()->staff->id], ['patientId', $patientId]])->whereNotNull('conferenceId')->first();
            } else {

                $inputApp = [
                    'udid' => Str::uuid()->toString(),
                    'staffId' => auth()->user()->staff->id,
                    'patientId' => $patientId,
                    'statusId' => 155,
                    'appointmentTypeId' => '50',
                    'startDateTime' => Carbon::now(),
                    'durationId' => 55,
                    'createdBy' => auth()->user()->id,
                    'providerId' => $provider,
                    'providerLocationId' => $providerLocation,
                    'entityType' => $entityType,
                ];
                $appointment = Appointment::create($inputApp);
                if ($appointment) {
                    $changeLog = [
                        'udid' => Str::uuid()->toString(), 'table' => 'appointments', 'tableId' => $appointment->id, 'providerId' => $provider, 'providerLocationId' => $providerLocation,
                        'value' => json_encode($inputApp), 'type' => 'created', 'ip' => request()->ip(), 'createdBy' => Auth::id(), 'entityType' => $entityType
                    ];
                    ChangeLog::create($changeLog);
                }
                $dataConvert = Helper::date(Carbon::now());
                $flag = Flag::where('udid', $request->input('flag'))->first();
                if ($flag) {
                    $flag = $flag->id;
                } else {
                    $flag = NULL;
                }
                // PatientFlag::create([
                //     'udid' => Str::uuid()->toString(),
                //     'patientId' => $patientId,
                //     'flagId' => $flag->id,
                //     'icon' => 'gif',
                //     'createdBy' => auth()->user()->id
                // ]);
                Note::create([
                    'date' => $dataConvert,
                    'categoryId' => 152,
                    'type' => 150,
                    'flagId' => $flag,
                    'note' => $request->input('note'),
                    'udid' => Str::uuid()->toString(),
                    'createdBy' => auth()->user()->id,
                    'referenceId' => $appointment->id,
                    'entityType' => 'appointment',
                    'providerId' => $provider,
                    'providerLocationId' => $providerLocation,
                ]);
                if (auth()->user()->roleId == 4) {
                    $userInput = Patient::where('id', auth()->user()->patient->id)->first();
                } else {
                    $userInput = Staff::where('id', auth()->user()->staff->id)->first();
                }
                $timeLine = [
                    'patientId' => $patientId, 'heading' => 'Appointment Note Added', 'title' => $request->input('note') . ' ' . '<b>By' . ' ' . $userInput->lastName . ',' . ' ' . $userInput->firstName . '</b>', 'type' => 6,
                    'createdBy' => Auth::id(), 'udid' => Str::uuid()->toString(), 'providerId' => $provider
                ];
                PatientTimeLine::create($timeLine);
            }
            $notificationData = [
                'body' => 'Your appointment going to start please join.',
                'title' => 'Appointment Reminder',
                'userId' => $patientIdx->userId,
                'isSent' => 0,
                'entity' => 'Confrence',
                'referenceId' => 'CONF' . $appointment->id,
                'createdBy' => auth()->user()->id,
                'providerId' => $provider
            ];
            $notification = Notification::create($notificationData);
            if ($notification) {
                $changeLog = [
                    'udid' => Str::uuid()->toString(), 'table' => 'notifications', 'tableId' => $notification->id, 'providerId' => $provider,
                    'value' => json_encode($notificationData), 'type' => 'created', 'ip' => request()->ip(), 'createdBy' => Auth::id()
                ];
                ChangeLog::create($changeLog);
            }
            $conf = ['conferenceId' => NULL];
            Appointment::where('patientId', $patientId)->update($conf);
            DB::statement("UPDATE `communicationCallRecords` SET `callStatusId`='49' WHERE `referenceId` IN ( SELECT concat('CONF',id) FROM appointments where conferenceId IS NULL) AND `entityType` = 'conferenceCall'");
            DB::statement("UPDATE `notifications` SET `isDelete`='1' , `deletedAt`=now() WHERE `referenceId` IN ( SELECT concat('CONF',id) FROM appointments where conferenceId IS NULL) AND `entity` = 'Confrence'");

            $conf = ['conferenceId' => 'CONF' . $appointment->id, 'providerId' => $provider];
            Appointment::where('id', $appointment->id)->update($conf);
            $pushnotification = new PushNotificationService();
            $notificationData = array(
                "body" => $notification->body,
                "title" => $notification->title,
                "type" => $notification->entity,
                "typeId" => $notification->referenceId,
            );
            $pushnotification->sendNotification([$notification->userId], $notificationData);
            $notificationInput = ['isSent' => '1', 'providerId' => $provider];
            Notification::where('id', $notification->id)->update($notificationInput);
            if ($notification) {
                $changeLog = [
                    'udid' => Str::uuid()->toString(), 'table' => 'notifications', 'tableId' => $notification->id, 'providerId' => $provider,
                    'value' => json_encode($notificationInput), 'type' => 'updated', 'ip' => request()->ip(), 'createdBy' => Auth::id()
                ];
                ChangeLog::create($changeLog);
            }


            $newAppointment = Appointment::where('id', $appointment->id)->first();
            $patientIdx = Patient::where('id', $patientId)->first();
            $commData = [
                'from' => auth()->user()->id,
                'referenceId' => $patientIdx->userId,
                'messageTypeId' => 104,
                'subject' => 'App Call',
                'priorityId' => 72,
                'messageCategoryId' => 40,
                'createdBy' => Auth::id(),
                'entityType' => 'appCall',
                'udid' => Str::uuid()->toString(),
                'providerId' => $provider,
                'providerLocationId' => $providerLocation
            ];
            $dataComm = Communication::create($commData);
            if ($dataComm) {
                $changeLog = [
                    'udid' => Str::uuid()->toString(), 'table' => 'communications', 'tableId' => $dataComm->id, 'providerId' => $provider, 'providerLocationId' => $providerLocation,
                    'value' => json_encode($commData), 'type' => 'created', 'ip' => request()->ip(), 'createdBy' => Auth::id(), 'entityType' => $entityType
                ];
                ChangeLog::create($changeLog);
            }
            $input = [
                'patientId' => $patientId,
                'callStatusId' => 47,
                'udid' => Str::uuid()->toString(),
                'referenceId' => 'CONF' . $appointment->id,
                'entityType' => 'conferenceCall',
                'communicationId' => $dataComm->id,
                'providerId' => $provider,
                'providerLocationId' => $providerLocation
            ];
            $comm = CommunicationCallRecord::create($input);
            if ($comm) {
                $changeLog = [
                    'udid' => Str::uuid()->toString(), 'table' => 'communicationCallRecords', 'tableId' => $comm->id, 'providerId' => $provider, 'providerLocationId' => $providerLocation,
                    'value' => json_encode($input), 'type' => 'created', 'ip' => request()->ip(), 'createdBy' => Auth::id(), 'entityType' => $entityType
                ];
                ChangeLog::create($changeLog);
            }
            $call = [
                'udid' => Str::uuid()->toString(),
                'createdBy' => auth()->user()->id,
                'communicationCallRecordId' => $comm->id,
                'staffId' => auth()->user()->staff->id,
                'providerId' => $provider,
                'providerLocationId' => $providerLocation,
                'entityType' => $entityType
            ];
            $callRecord = CallRecord::create($call);
            if ($callRecord) {
                $changeLog = [
                    'udid' => Str::uuid()->toString(), 'table' => 'callRecords', 'tableId' => $callRecord->id, 'providerId' => $provider, 'providerLocationId' => $providerLocation,
                    'value' => json_encode($call), 'type' => 'created', 'ip' => request()->ip(), 'createdBy' => Auth::id(), 'entityType' => $entityType
                ];
                ChangeLog::create($changeLog);
            }
            $callTime = [
                'udid' => Str::uuid()->toString(),
                'createdBy' => auth()->user()->id,
                'callRecordId' => $callRecord->id,
                'providerId' => $provider,
                'providerLocationId' => $providerLocation
            ];
            /*$callRecordTime = CallRecordTime::create($callTime);
            if ($callRecordTime) {
                $changeLog = [
                    'udid' => Str::uuid()->toString(), 'table' => 'callRecordTimes', 'tableId' => $callRecordTime->id,
                    'value' => json_encode($callTime), 'type' => 'created', 'ip' => request()->ip(), 'createdBy' => Auth::id()
                ];
                ChangeLog::create($changeLog);
            }*/
            $message = ['message' => trans('messages.createdSuccesfully')];
            $data = fractal()->item($newAppointment)->transformWith(new AppointmentTransformer())->toArray();
            return array_merge($message, $data);
        } catch (Exception $e) {
            if (isset(auth()->user()->id)) {
                $userId = auth()->user()->id;
            } else {
                $userId = "";
            }
            ErrorLogGenerator::createLog($request, $e, $userId);
            throw new \RuntimeException($e);
        }
    }

    // Update Appointment Status
    public function appointmentStatus($request, $id)
    {
        try {
            $provider = Helper::providerId();
            $providerLocation = Helper::providerLocationId();
            $input = [
                'statusId' => $request->input('statusId'),
                'updatedBy' => auth()->user()->id,
                'providerId' => $provider,
                'providerLocationId' => $providerLocation
            ];
            $app = Appointment::where('udid', $id)->update($input);
            // $changeLog = [
            //     'udid' => Str::uuid()->toString(), 'table' => 'appointments', 'tableId' => $app,'providerId'=>$provider.'providerLocationId' => $providerLocation,
            //     'value' => json_encode($input), 'type' => 'updated', 'ip' => request()->ip(), 'createdBy' => Auth::id()
            // ];
            // ChangeLog::create($changeLog);
            $appointment = Appointment::where('udid', $id)->first();
            $patientIdx = Patient::where('id', $appointment->patientId)->first();
            if ($appointment->statusId == 155) {
                $body = 'Your appointment has been accepted';
            } elseif ($appointment->statusId == 141) {
                $body = 'Your appointment has been rejected';
                if (!empty($request->cancellationNote)) {
                    $cancellation = [
                        'cancellationNote' => $request->cancellationNote,
                        'updatedBy' => auth()->user()->id,
                        'providerId' => $provider,
                        'providerLocationId' => $providerLocation
                    ];
                } else {
                    $cancellation = [
                        'cancellationNote' => 'Time is Not Available',
                        'updatedBy' => auth()->user()->id,
                        'providerId' => $provider,
                        'providerLocationId' => $providerLocation
                    ];
                }
                Appointment::where('udid', $id)->update($cancellation);
            } else {
                $body = 'Your appointment has been pending';
            }
            $notificationInput = [
                'body' => $body,
                'title' => 'Appointment Status Update',
                'userId' => $patientIdx->userId,
                'isSent' => 0,
                'entity' => 'Appointment',
                'referenceId' => $appointment->id,
                'createdBy' => auth()->user()->id,
                'providerId' => $provider,
                'providerLocationId' => $providerLocation
            ];
            $notification = Notification::create($notificationInput);
            // $changeLog = [
            //     'udid' => Str::uuid()->toString(), 'table' => 'notifications', 'tableId' => $notification->id,'providerId'=>$provider,'providerLocationId' => $providerLocation,
            //     'value' => json_encode($notificationInput), 'type' => 'created', 'ip' => request()->ip(), 'createdBy' => Auth::id()
            // ];
            // ChangeLog::create($changeLog);

            $pushnotification = new PushNotificationService();
            $notificationData = array(
                "body" => $notification->body,
                "title" => $notification->title,
                "type" => $notification->entity,
                "typeId" => $notification->referenceId,
            );
            $pushnotification->sendNotification([$notification->userId], $notificationData);
            $notificationUpdate = ['isSent' => '1', 'providerId' => $provider, 'providerLocationId' => $providerLocation, 'updatedBy' => Auth::id()];
            Notification::where('id', $notification->id)->update($notificationUpdate);

            // $changeLog = [
            //     'udid' => Str::uuid()->toString(), 'table' => 'notifications', 'tableId' => $notification->id,'providerId'=>$provider,'providerLocationId' => $providerLocation,
            //     'value' => json_encode($notificationUpdate), 'type' => 'updated', 'ip' => request()->ip(), 'createdBy' => Auth::id()
            // ];
            // ChangeLog::create($changeLog);
            if ($appointment->statusId == 141) {
                $appointments = Appointment::where('udid', $id)->first();
                $message = ['message' => 'Appointment Rejected'];
                $reason = fractal()->item($appointments)->transformWith(new AppointmentTransformer())->toArray();
                return array_merge($message, $reason);
            } elseif ($appointment->statusId == 155) {
                return response()->json(['message' => 'Appointment Accepted']);
            } else {
                return response()->json(['message' => 'Appointment is Pending']);
            }
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    // List New Appointment
    public function appointmentListNew($request, $id)
    {
        try {
            $patient = Helper::entity('patient', $id);
            $notAccess = Helper::haveAccess($patient);
            $data = Appointment::with('status')->select('appointments.*')->where('patientId', $patient)->whereRaw('(conferenceId IS NOT NULL || startDateTime >="' . Carbon::now() . '")');

            $data->leftJoin('providers', 'providers.id', '=', 'appointments.providerId')->where('providers.isActive', 1)->whereNull('providers.deletedAt');
            $data->leftJoin('programs', 'programs.id', '=', 'appointments.programId')->where('programs.isActive', 1)->whereNull('programs.deletedAt');

            $data->leftJoin('providerLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocations.id')->where('appointments.entityType', '=', 'Country');
            })->whereNull('providerLocations.deletedAt');

            $data->leftJoin('providerLocationStates', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationStates.id')->where('appointments.entityType', '=', 'State');
            })->whereNull('providerLocationStates.deletedAt');

            $data->leftJoin('providerLocationCities', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'providerLocationCities.id')->where('appointments.entityType', '=', 'City');
            })->whereNull('providerLocationCities.deletedAt');

            $data->leftJoin('subLocations', function ($join) {
                $join->on('appointments.providerLocationId', '=', 'subLocations.id')->where('appointments.entityType', '=', 'subLocation');
            })->whereNull('subLocations.deletedAt');

            if (request()->header('providerId')) {
                $provider = Helper::providerId();
                $data->where('appointments.providerId', $provider);
            }
            if (request()->header('providerLocationId')) {
                $providerLocation = Helper::providerLocationId();
                if (request()->header('entityType') == 'Country') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'Country']]);
                }
                if (request()->header('entityType') == 'State') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'State']]);
                }
                if (request()->header('entityType') == 'City') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'City']]);
                }
                if (request()->header('entityType') == 'subLocation') {
                    $data->where([['appointments.providerLocationId', $providerLocation], ['appointments.entityType', 'subLocation']]);
                }
            }
            if (request()->header('programId')) {
                $program = Helper::programId();
                $entityType = Helper::entityType();
                $data->where([['appointments.programId', $program], ['appointments.entityType', $entityType]]);
            }
            if (!$notAccess) {
                if (auth()->user()->roleId == 3) {
                    if (!Helper::haveAccessAction(null, 62) && !Helper::haveAccessAction(null, 37)) {
                        $data->where('staffId', auth()->user()->staff->id);
                    }
                }
                $data = $data->orderBy('startDateTime', 'ASC')->get();
                return fractal()->collection($data)->transformWith(new AppointmentSearchTransformer())->toArray();
            } else {
                return $notAccess;
            }
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    // Update Appointment Status
    public function appointmentStatusUpdate($request, $id)
    {
        try {
            $provider = Helper::providerId();
            $providerLocation = Helper::providerLocationId();
            $data = Appointment::where('udid', $id)->first();
            if (!is_null($data) && $data->appoinmentStatus == null) {
                $input = [
                    'appoinmentStatus' => $request->input('appoinmentStatus'),
                    'updatedBy' => auth()->user()->id,
                    'providerId' => $provider,
                    'providerLocationId' => $providerLocation
                ];
                Appointment::where('udid', $id)->update($input);
                return response()->json(['message' => trans('messages.updatedSuccesfully')]);
            }
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }
}
