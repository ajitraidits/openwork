<?php

namespace App\Services\Api;

use App\Services\Api\QuestionChangeService;
use Exception;
use App\Helper;
use App\Models\User\User;
use App\Models\Staff\Staff;
use Illuminate\Support\Str;
use App\Models\Patient\Patient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use App\Models\GlobalCode\GlobalCode;
use App\Models\Questionnaire\Question;
use App\Models\Notification\Notification;
use App\Models\ConfigMessage\ConfigMessage;
use App\Models\Questionnaire\QuestionScore;
use App\Services\Api\QuestionChangeService;
use App\Models\Questionnaire\QuestionOption;
use App\Models\Questionnaire\QuestionChanges;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\Questionnaire\QuestionnaireField;
use App\Models\Questionnaire\ClientQuestionScore;
use App\Models\Questionnaire\ClientResponseAnswer;
use App\Models\Questionnaire\ClientResponseProgram;
use App\Models\Questionnaire\QuestionnaireQuestion;
use App\Models\Questionnaire\QuestionnaireTemplate;
use App\Models\Questionnaire\QuestionOptionProgram;
use App\Models\Questionnaire\ClientQuestionResponse;
use App\Models\QuestionnaireSection\QuestionSection;
use App\Models\Questionnaire\ClientQuestionnaireAssign;
use App\Transformers\Questionnaire\QuestionTransformer;
use App\Models\Questionnaire\ClientQuestionnaireTemplate;
use App\Models\QuestionnaireSection\QuestionnaireSection;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use App\Transformers\Questionnaire\QuestionOptionTransformer;
use App\Transformers\Questionnaire\QuestionOptionProgramTransformer;
use App\Transformers\ClientQuestionnaire\ClientQuestionnaireTransformer;
use App\Transformers\ClientQuestionnaire\ClientQuestionnaireAssignTransformer;
use App\Transformers\ClientQuestionnaire\ClientQuestionnQuestionnaireTransformer;

class ClientQuestionnaireService
{

    public function addClientQuestionnaireTemplate($request)
    {
        try {
            $post = $request->all();
            unset($post["provider"], $post["location"]);
            $all = 0;
            $i = 0;
            $alreadyAssigned = 0;
            $lastIdArr = array();
            foreach ($post as $parameter) {
                if (isset($parameter["entityType"])) {
                    $entitypeId = $parameter["entityType"];
                } else {
                    $entitypeId = "";
                }
                $userIds = "";
                $user_Id = "";
                if ($entitypeId == "246") {
                    // 246 for staff
                    if (isset($parameter["allStaff"]) && !empty($parameter["allStaff"])) {
                        $all = 1;
                        $users = Staff::where("isActive", 1)->get();
                    } else {
                        $all = 0;
                        $staff = Staff::where("udid", $parameter["referenceId"])->first();
                        if (isset($staff->id)) {
                            $userIds = $staff->id;
                            $user_Id = $staff->userId;
                            $userUdid = $staff->udid;
                            $email = $staff->user->email;
                            $fullName = $staff->firstName . " " . $staff->lastName;
                        }
                    }

                } elseif ($entitypeId == "247") {
                    // 247 for patient
                    if (isset($parameter["allPatient"]) && !empty($parameter["allPatient"])) {
                        $all = 1;
                        $users = Patient::where("isActive", 1)->get();
                    } else {
                        $all = 0;
                        $patient = Patient::where("udid", $parameter["referenceId"])->first();
                        if (isset($patient->id)) {
                            $userIds = $patient->id;
                            $user_Id = $patient->userId;
                            $userUdid = $patient->udid;
                            $email = $patient->user->email;
                            $fullName = $patient->firstName . " " . $patient->lastName;
                        } else {
                            $userUdid = "";
                            $user_Id = "";
                            $email = "";
                            $fullName = "";
                        }
                    }
                }
                $template = QuestionnaireTemplate::where("udid", $parameter["questionnaireTemplateId"])->first();
                if (isset($userIds)) {
                    if ($all > 0) {
                        if (count($users) > 0) {
                            foreach ($users as $user) {
                                // for all staff or patient send assign request using allStaff or allPatient
                                $userIds = $user->id;
                                $user_Id = $user->userId;
                                $userUdid = $user->udid;
                                if (isset($user->user->email)) {
                                    $email = $user->user->email;
                                } else {
                                    $email = "";
                                }
                                if (isset($user->firstName)) {
                                    $fullName = $user->firstName . " " . $patient->lastName;
                                } else {
                                    $fullName = "";
                                }

                                $insertObj = [
                                    "questionnaireTemplateId" => $template->questionnaireTemplateId,
                                    "referenceId" => $userIds,
                                    "entityType" => $entitypeId,
                                    'udid' => Str::uuid()->toString(),
                                    'createdBy' => Auth::id()
                                ];

                                $checkAssign = ClientQuestionnaireAssign::where("referenceId", $userIds)
                                    ->where("entityType", $entitypeId)
                                    ->where("questionnaireTemplateId", $template->questionnaireTemplateId)
                                    ->latest("createdAt")->first();
                                $checkAssignStatus = 1;
                                if ($checkAssign) {
                                    $checkAssignStatus = 0;
                                    // status: complete,pending or inprogress.
                                    $ClientQuestionnaireTemplate = ClientQuestionnaireTemplate::where("clientQuestionnaireAssignId", $checkAssign->clientQuestionnaireAssignId)->first();
                                    if (isset($ClientQuestionnaireTemplate->status) && $ClientQuestionnaireTemplate->status === "Complete") {
                                        $checkAssignStatus = 1;
                                    }
                                }

                                if ($checkAssignStatus) {
                                    $lastId = ClientQuestionnaireAssign::insertGetId($insertObj);
                                    if ($lastId) {
                                        $lastIdArr[$i]["lastId"] = $lastId;
                                        $lastIdArr[$i]["email"] = $email;
                                        $lastIdArr[$i]["fullName"] = $fullName;
                                        $lastIdArr[$i]["entitypeId"] = $entitypeId;
                                        $lastIdArr[$i]["userUdid"] = $userUdid;
                                        $lastIdArr[$i]["user_Id"] = $user_Id;
                                        // send email notification
                                        // $clientQuestionnaireAssign = ClientQuestionnaireAssign::where("clientQuestionnaireAssignId",$lastId)->first();
                                        // $this->sendEmailForAssignQuestionniare($clientQuestionnaireAssign,$template,$email,$fullName,$entitypeId,$userUdid);
                                        // // for patient
                                        // // if($entitypeId == "247"){
                                        //     $this->sendNotificationForAssignQuestionniare($clientQuestionnaireAssign,$template,$email,$fullName,$entitypeId,$user_Id);
                                        // // }
                                    }
                                }
                            }
                        }
                    } else if ($userIds) {
                        $insertObj = [
                            "questionnaireTemplateId" => $template->questionnaireTemplateId,
                            "referenceId" => $userIds,
                            "entityType" => $entitypeId,
                            'udid' => Str::uuid()->toString(),
                            'createdBy' => Auth::id()
                        ];

                        $checkAssign = ClientQuestionnaireAssign::where("referenceId", $userIds)
                            ->where("entityType", $entitypeId)
                            ->where("questionnaireTemplateId", $template->questionnaireTemplateId)
                            ->latest("createdAt")->first();

                        $checkAssignStatus = 1;
                        if ($checkAssign) {
                            $checkAssignStatus = 0;
                            // status: complete,pending or inprogress.
                            $ClientQuestionnaireTemplate = ClientQuestionnaireTemplate::where("clientQuestionnaireAssignId", $checkAssign->clientQuestionnaireAssignId)->first();
                            if (isset($ClientQuestionnaireTemplate->status) && $ClientQuestionnaireTemplate->status === "Complete") {
                                $checkAssignStatus = 1;
                            } else { // Updated by S
                                $alreadyAssigned++;
                            }
                        }

                        if ($checkAssignStatus) {
                            $lastId = ClientQuestionnaireAssign::insertGetId($insertObj);
                            if ($lastId) {
                                $lastIdArr[$i]["lastId"] = $lastId;
                                $lastIdArr[$i]["email"] = $email;
                                $lastIdArr[$i]["fullName"] = $fullName;
                                $lastIdArr[$i]["entitypeId"] = $entitypeId;
                                $lastIdArr[$i]["userUdid"] = $userUdid;
                                $lastIdArr[$i]["user_Id"] = $user_Id;
                            }
                        }
                    } else {
                        return response()->json(['message' => "user not found."], 401);
                    }
                    if ($checkAssignStatus) {
                        if ($entitypeId == "247") {
                            if (isset($parameter["questionnaireCustomField"])) {
                                foreach ($parameter["questionnaireCustomField"] as $k => $input) {
                                    // echo $k;
                                    $insertArr = array(
                                        'udid' => Str::uuid()->toString(),
                                        "questionnaireId" => $template->questionnaireTemplateId,
                                        "parameterKey" => $k,
                                        "parameterValue" => $input,
                                        "entityType" => "assignPatient",
                                        "referenceId" => $userIds,
                                        "createdBy" => Auth::id()
                                    );
                                    QuestionnaireField::insertGetId($insertArr);
                                }
                            }
                        }
                    }
                }
                $i++;
            }

            if (count($lastIdArr) > 0) {
                $sendEmailObj = $this->getSendEmailDataAndNotification($lastIdArr, $template);
                if (isset($sendEmailObj["to"]) && count($sendEmailObj["to"]) > 0) {
                    $to = $sendEmailObj["to"];
                    $messageObj = $sendEmailObj["messageObj"];
                    $fromName = $sendEmailObj["fromName"];
                    $subject = $sendEmailObj["subject"];
                    Helper::sendInBulkMail($to, $fromName, $messageObj, $subject);
                }
            }
            if ($alreadyAssigned > 0) {
                return response()->json(['message' => "Previous assignment is already in process."], 200);
            }
            return response()->json(['message' => trans('messages.createdSuccesfully')], 200);
        } catch (\Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function sendNotificationForAssignQuestionniare($clientQuestionnaireAssign, $template, $email, $fullName, $entitypeId, $userId): void
    {
        try {
            $provider = Helper::providerId();
            $providerLocation = Helper::providerLocationId();
            Notification::create([
                'body' => 'There is Questionnaire for You With' . ' ' . $fullName,
                'title' => 'New Questionnaire',
                'userId' => $userId,
                'isSent' => 0,
                'entity' => 'Questionnaire',
                'referenceId' => $clientQuestionnaireAssign->clientQuestionnaireAssignId,
                'createdBy' => Auth::id(),
                'providerId' => $provider,
                'providerLocationId' => $providerLocation
            ]);
        } catch (\Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function getSendEmailDataAndNotification($lastIdArr, $template): ?array
    {
        try {
            $emailArr = array();
            $j = 0;
            foreach ($lastIdArr as $val) {
                $clientQuestionnaireAssign = ClientQuestionnaireAssign::where("clientQuestionnaireAssignId", $val["lastId"])->first();
                if (!empty($val["email"])) {
                    // Send Email Notification
                    // $sendEmailObj = $this->sendEmailForAssignQuestionniare($clientQuestionnaireAssign,$template,$val["email"],$val["fullName"],$val["entitypeId"],$val["userUdid"],$j,$emailArr);
                    $userEmail = $val["email"];
                    $url = "https://dev.icc-health.com/web-v2/#/templateResponse/" . $clientQuestionnaireAssign->udid . "?provider=&location=&userType=" . $val["entitypeId"] . "&userId=" . $val["userUdid"] . "&userName=" . $val["fullName"] . "";
                    /*
                        $message = '<tr><td style="padding: 15px;">
                            <p style="font-size: 13px;margin: 0 0 10px;"><span style="width: 250px;display:inline-block">A New questionniare Assign for You.Please <a href="' . $url . '">Click Here</a> For fillup Questionnaire.</span></p>
                            <td style="padding: 15px;" vertical-align="top">
                            <div>
                            </div></td></tr>';
                    */

                    $variablesArr = array(
                        "fullName" => $val["fullName"],
                        // "message" => $message,
                        "assignUrl" => $url,
                        "heading" => 'New Questionnaire Assign for You',
                    );

                    // email body message
                    $msgObj = ConfigMessage::where("type", "questionnaireAssign")
                        ->where("entityType", "sendMail")
                        ->first();
                    // email header
                    $msgHeaderObj = ConfigMessage::where("type", "header")
                        ->where("entityType", "sendMail")
                        ->first();
                    // email footer
                    $msgFooterObj = ConfigMessage::where("type", "footer")
                        ->where("entityType", "sendMail")
                        ->first();

                    if (isset($msgObj->messageBody)) {
                        $messageBody = $msgObj->messageBody;
                        if (isset($msgHeaderObj->messageBody) && !empty($msgHeaderObj->messageBody)) {
                            $messageBody = $msgHeaderObj->messageBody . $messageBody;
                        }
                        if (isset($msgFooterObj->messageBody) && !empty($msgFooterObj->messageBody)) {
                            $messageBody = $messageBody . $msgFooterObj->messageBody;
                        }
                        $messageObj = Helper::getMessageBody($messageBody, $variablesArr);
                    }

                    if (isset($userEmail) && !empty($userEmail)) {
                        $to = $userEmail;
                        if (isset($msgObj->otherParameter)) {
                            $otherParameter = json_decode($msgObj->otherParameter);
                            if (isset($otherParameter->fromName)) {
                                $fromName = $otherParameter->fromName;
                            } else {
                                $fromName = "Virtare Health";
                            }
                        } else {
                            $fromName = "Virtare Health";
                        }
                        $subject = @$msgObj->subject ?? "New Assign Questionnaire";
                    }

                    $emailArr["to"][$j] = $to;
                    $emailArr["messageObj"][$j] = $messageObj;
                    $emailArr["fromName"] = $fromName;
                    $emailArr["subject"] = $subject;
                    $j++;
                    // Send Notification For APP.
                    $this->sendNotificationForAssignQuestionniare($clientQuestionnaireAssign, $template, $val["email"], $val["fullName"], $val["entitypeId"], $val["user_Id"]);
                }
            }
            return $emailArr;
        } catch (\Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function sendEmailForAssignQuestionniare($clientQuestionnaireAssign, $template, $email, $fullName, $entitypeId, $userUdid, $j)
    {


        // if(!empty($messageObj)){
        //     Helper::commonMailjet($to, $fromName, $messageObj, $subject);
        // }
    }

    public function getAssignQuestionnaireTemplate($request, $id)
    {
        try {
            if (!$id) {
                $loginUserId = Auth::id();
                $role = Auth::user()->roleId;

                if (isset($request->search)) {
                    $search = $request->search;
                } else {
                    $search = "";
                }
                $assignByUser = "";
                if ($role == "1" || $role == "3") {
                    if ($request->entityType == "246") {
                        if ($request->referenceId) {
                            $staff = Staff::where("udid", $request->referenceId)->first();
                            if (isset($staff->id)) {
                                $assignToUser = $staff->id;
                            } else {
                                $assignToUser = "";
                            }
                        } else {
                            $assignToUser = "";
                        }

                        $data = DB::select(
                            "CALL getAssignedClientQuestionnaireByStaff('" . $assignByUser . "'," . $assignToUser . ",'" . $search . "')",
                        );
                    } elseif ($request->entityType == "247") {
                        if ($request->referenceId) {
                            $patient = Patient::where("udid", $request->referenceId)->first();
                            if (isset($patient->id)) {
                                $assignToUser = $patient->id;
                            } else {
                                $assignToUser = "";
                            }
                        } else {
                            $assignToUser = "";
                        }
                        $data = DB::select(
                            "CALL getAssignedClientQuestionnaireByPatient('" . $assignByUser . "','" . $assignToUser . "','" . $search . "')",
                        );
                    } else {
                        if ($role == "3") {
                            $staff = Staff::where("userId", $loginUserId)->first();
                            $assignToUser = $staff->id;
                        } else {
                            $assignToUser = "";
                        }

                        $data = DB::select(
                            "CALL getAssignedClientQuestionnaire('" . $assignByUser . "','" . $assignToUser . "','" . $search . "')",
                        );
                    }
                } elseif ($role == "4") {
                    if (isset($request->referenceId) && $request->entityType == "247") {
                        $patient = Patient::where("udid", $request->referenceId)->first();
                        $assignToUser = $patient->id;
                        $data = DB::select(
                            "CALL getAssignedClientQuestionnaireByPatient('" . $assignByUser . "','" . $assignToUser . "','" . $search . "')",
                        );
                    } else {
                        $patient = Patient::where("userId", $loginUserId)->first();
                        $assignToUser = $patient->id;
                        $data = DB::select(
                            "CALL getAssignedClientQuestionnaireByPatient('" . $assignByUser . "','" . $assignToUser . "','" . $search . "')",
                        );
                    }
                }

                $data = $this->paginate($data);
                return fractal()->collection($data)->transformWith(new ClientQuestionnaireAssignTransformer())->paginateWith(new IlluminatePaginatorAdapter($data))->toArray();
                // return fractal()->collection($data)->transformWith(new ClientQuestionnaireAssignTransformer())->toArray();
            }
            $data = ClientQuestionnaireTemplate::where("udid", $id);
            $data = $data->with("questionnaireTemplate", "templateType", "clientQuestionResponse.questionnaireQuestion")
                ->first();
            return fractal()->item($data)->transformWith(new ClientQuestionnaireAssignTransformer())->toArray();
        } catch (\Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function getfillUpQuestionnaireForApp($request, $id): void
    {
        try {
            $data = ClientQuestionnaireAssign::where("clientQuestionnaireAssignId", $id)->first();
            $this->getfillUpQuestionnaire($request, $data->udid);
        } catch (\Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function getDetail($data)
    {
        try {
            /** Need data on this function, below in mention.
             * entityType,referenceId, parameterKey
             * like this getQuestionnaireField("entityType","referenceId","parameterKey")
             **/

            // get User Type
            $objData = '';
            $templateCustomField = QuestionnaireField::getAlQuestionnaireField("questionnaireTemplate", $data->questionnaireTemplateId);
            QuestionnaireField::getQuestionnaireField("questionnaireTemplate", $data->questionnaireTemplateId, "responseShow");
            $questionnaireFieldForSlot = QuestionnaireField::getQuestionnaireField("questionnaireTemplate", $data->questionnaireTemplateId, "slotTypeId");
            $questionnaireFieldForUserType = QuestionnaireField::getQuestionnaireField("questionnaireTemplate", $data->questionnaireTemplateId, "userTypeId");
            $questionnaireFieldForScoreType = QuestionnaireField::getQuestionnaireField("questionnaireTemplate", $data->questionnaireTemplateId, "scoreTypeId");
            if ($questionnaireFieldForUserType) {
                // if($questionnaireFieldForUserType->parameterValue == "330"){
                //     // for patient
                // }elseif($questionnaireFieldForUserType->parameterValue == "331"){
                //     // for Staff
                // }

                $objData = $data->toArray();
                if (isset($data->questionnaireTemplate->templateType->name)) {
                    $objData["templateType"] = $data->questionnaireTemplate->templateType->name;
                }

                if (isset($questionnaireFieldForScoreType->parameterValue)) {
                    $objData["scoreTypeId"] = $questionnaireFieldForScoreType->parameterValue;
                    if (isset($questionnaireFieldForScoreType->getOptionName->name)) {
                        $objData["scoreType"] = $questionnaireFieldForScoreType->getOptionName->name;
                    }
                }

                if (isset($questionnaireFieldForSlot->parameterValue)) {
                    $objData["slotTypeId"] = $questionnaireFieldForSlot->parameterValue;
                    if (isset($questionnaireFieldForSlot->getOptionName->name)) {
                        $objData["slotType"] = $questionnaireFieldForSlot->getOptionName->name;
                    } else {
                        $objData["slotType"] = "";
                    }
                }

                if (isset($questionnaireFieldForUserType->parameterValue)) {
                    $objData["userTypeId"] = $questionnaireFieldForUserType->parameterValue;
                    $objData["userType"] = $questionnaireFieldForUserType->getOptionName->name;
                }
                // get fillup by
                if ($data->entityType == "246") {
                    // for staff
                    $staff = Staff::where("id", $data->referenceId)->first();
                    if (isset($staff->udid)) {
                        $objData["fillUpUser"] = $staff->firstName . " " . $staff->lastName;
                        $objData["fillUpUserId"] = $staff->udid;
                    }
                } elseif ($data->entityType == "247") {
                    // for Patient
                    $patient = Patient::where("id", $data->referenceId)->first();
                    if (isset($patient->udid)) {
                        $objData["fillUpUser"] = $patient->firstName . " " . $patient->lastName;
                        $objData["fillUpUserId"] = $patient->udid;
                    }

                }

                if (isset($data->createdBy)) {
                    $user = User::where("id", $data->createdBy)->first();
                    if (isset($user->udid)) {
                        if ($user->roleId == "3" || $user->roleId == "1") {
                            // for staff
                            $staff = Staff::where("userId", $user->id)->first();
                            if (isset($staff->udid)) {
                                $objData["assignBy"] = $staff->firstName . " " . $staff->lastName;
                                $objData["assignById"] = $staff->udid;
                            }
                        } elseif ($user->roleId == "4") {
                            // for Patient
                            $patient = Patient::where("userId", $user->id)->first();
                            if (isset($patient->udid)) {
                                $objData["assignBy"] = $patient->firstName . " " . $patient->lastName;
                                $objData["assignById"] = $patient->udid;
                            }
                        }
                    }
                }


                if (!empty($templateCustomField)) {
                    $dataObjArr = [];
                    foreach ($templateCustomField as $v) {
                        if (isset($v->parameterValue)) {
                            $dataObjArr[$v->parameterKey] = $v->parameterValue;
                        }
                    }
                    $objData["questionnaireCustomField"] = $dataObjArr;
                }

                // get clientQuestionnaireTemplate id existing fillup response
                $clientQuestionnaireTemplate = ClientQuestionnaireTemplate::where("clientQuestionnaireAssignId", $data->clientQuestionnaireAssignId)->where("isActive", "1")->latest("createdAt")->first();

                // get score
                if (isset($clientQuestionnaireTemplate->clientFillUpQuestionnaireId)) {
                    $objData["score"] = $this->getQuestionnaireTemplateScore($clientQuestionnaireTemplate->udid);
                } else {
                    $objData["score"] = "";
                }

                $assignSection = QuestionnaireQuestion::leftjoin("questionnaireSections", function ($join) {
                    $join->on('questionnaireQuestions.referenceId', '=', 'questionnaireSections.questionnaireSectionId');
                })
                    ->select("questionnaireQuestions.*", "questionnaireSections.sectionName", "questionnaireSections.udid as sectionId", "questionnaireSections.questionnaireSectionId")
                    ->where("questionnaireQuestions.questionnaireTempleteId", $data->questionnaireTemplateId)
                    ->where("questionnaireQuestions.entityType", "questionnaireSection")
                    ->get();

                $objData["assignSection"] = $assignSection->toArray();
                $i = 0;
                foreach ($assignSection as $sections) {
                    $questions = QuestionSection::with("questionsDataType", "questionOption");
                    $questions->join("questions", "questions.questionId", "=", "questionSections.questionId");
                    $questions->select("questionSections.*", "questions.udid as questionUdid", "questions.question", "questions.dataTypeId", "questions.questionType");
                    $questions->where("questionnaireSectionId", $sections->questionnaireSectionId);
                    $questions->where("questions.parent", "0");
                    $questions->where("questionSections.isActive", "1");
                    $questions = $questions->get();
                    $objQuestion = [];
                    $k = 0;
                    $answer = "";
                    foreach ($questions as $question) {
                        // questionSection
                        // if($questionnaireFieldForSlot->parameterValue == "334"){
                        //     // for One Time
                        // }elseif($questionnaireFieldForSlot->parameterValue == "336"){

                        // }
                        // get latest existing fillup response answer
                        if (isset($clientQuestionnaireTemplate->clientFillUpQuestionnaireId)) {
                            // getting filled question using clientQuestionnaireTemplate id and question id
                            if (isset($clientQuestionnaireTemplate->clientFillUpQuestionnaireId)) {
                                $clientQuestionResponse = ClientQuestionResponse::where("clientFillUpQuestionnaireId", $clientQuestionnaireTemplate->clientFillUpQuestionnaireId)
                                    ->where("isActive", "1")
                                    ->where("questionnaireQuestionId", $question->questionId)
                                    ->where("entityType", "questionnaireSection")
                                    ->where("referenceId", $sections->questionnaireSectionId)
                                    ->first();
                            }
                            $answer = null;
                            // if question is filled getting filled answer.
                            if (isset($clientQuestionResponse->clientFillupQuestionnaireQuestionId)) {
                                if (@$question->dataTypeId && $question->dataTypeId == "244") {
                                    $cleintQuestionAns = ClientResponseAnswer::where("clientFillupQuestionnaireQuestionId", $clientQuestionResponse->clientFillupQuestionnaireQuestionId)->where("isActive", "1")->get();
                                    if (!empty($cleintQuestionAns)) {
                                        $answer = array();
                                        foreach ($cleintQuestionAns as $vall) {
                                            $answer[] = $vall->response;
                                        }
                                    }
                                } else {
                                    $cleintQuestionAns = ClientResponseAnswer::where("clientFillupQuestionnaireQuestionId", $clientQuestionResponse->clientFillupQuestionnaireQuestionId)->where("isActive", "1")->first();
                                    if (isset($cleintQuestionAns->response)) {
                                        $answer = $cleintQuestionAns->response;
                                    }
                                }
                            }
                        }

                        $questionUpdate = QuestionChanges::where("questionId", $question->questionId);
                        $questionUpdate->where("sectionId", $sections->questionnaireSectionId);
                        $questionUpdate->where("entityType", "template");
                        $questionUpdate->orWhere("questionId", $question->questionId);
                        $questionUpdate->Where("entityType", "question");
                        $questionUpdate = $questionUpdate->first();

                        $dataObj = "";
                        if (isset($questionUpdate->dataObj)) {
                            $dataObj = json_decode($questionUpdate->dataObj);
                        }

                        $dataType = [];
                        if (isset($dataObj->dataTypeId)) {
                            $dataType = GlobalCode::where("id", $dataObj->dataTypeId)->first();
                        }
                        if (isset($dataObj->questionType)) {
                            GlobalCode::where("id", $dataObj->questionType)->first();
                        }

                        // $customFieldDataObj = array();
                        // if(isset($dataObj->questionnaireCustomField) && !empty($dataObj->questionnaireCustomField)){
                        //     foreach($dataObj->questionnaireCustomField as $k => $v){
                        //         $customFieldDataObj[$k] = $v;
                        //     }
                        // }

                        $objQuestion[$k]["id"] = $question->questionUdid;
                        $objQuestion[$k]["questionId"] = $question->questionId;
                        $objQuestion[$k]["question"] = (!empty($dataObj)) ? $dataObj->question : $question->question;
                        $objQuestion[$k]["dataTypeId"] = (!empty($dataObj)) ? $dataObj->dataTypeId : $question->dataTypeId;
                        if (@$question->questionType) {
                            $objQuestion[$k]["questionTypeId"] = $question->questionType;
                            $g = GlobalCode::where("id", $question->questionType)->first();
                            if (isset($g->name)) {
                                $objQuestion[$k]["questionType"] = $g->name;
                            }
                        }
                        $objQuestion[$k]["dataType"] = (!empty($dataType)) ? $dataType->name : $question->questionsDataType->name;
                        $objQuestion[$k]["answerFillUp"] = isset($answer) ? $answer : "";
                        if (isset($question->questionOption) && !empty($question->questionOption)) {
                            $j = 0;
                            $optionObjaRR = [];
                            if ($sections->questionnaireSectionId) {
                                $sectionId = $sections->questionnaireSectionId;
                                $editType = "template";
                                $optionObjaRR = QuestionChanges::where("sectionId", $sections->questionnaireSectionId);
                                $optionObjaRR->where("questionId", $question->questionId);
                                $optionObjaRR->where("entityType", "templateOption");
                                $optionObjaRR = $optionObjaRR->first();
                            } else {
                                $sectionId = 0;
                            }

                            if (isset($optionObjaRR->udid)) {
                                $objQuestion[$k]["options"] = self::getAllCustomOption($optionObjaRR, $sectionId, $editType, $clientQuestionnaireTemplate);
                            } else {
                                foreach ($question->questionOption as $opt) {
                                    $objQuestion[$k]["options"][$j] = $this->getQuestionOption($opt, $clientQuestionnaireTemplate);
                                    $j++;
                                }
                            }
                        }
                        // $objQuestion[$k]["questionOption"] = fractal()->collection($question->questionOption)->transformWith(new QuestionOptionTransformer())->serializeWith(new \Spatie\Fractalistic\ArraySerializer())->toArray();
                        $k++;
                    }
                    $objData["assignSection"][$i]["questions"] = $objQuestion;
                    $i++;
                }
            }
            return $objData;
        } catch (\Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function getfillUpQuestionnaire($request, $id)
    {
        try {
            $data = ClientQuestionnaireAssign::with('questionnaireTemplate')
                ->where("udid", $id)->first();
            $objData = [];
            if (!empty($data)) {
                $objData = $this->getDetail($data);
            }
            return ["data" => $objData];
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function getfillUpQuestionnaireNew($request, $id)
    {
        try {
            $data = ClientQuestionnaireAssign::with('questionnaireTemplate')
                ->where("udid", $id)
                ->first();
            $objData = [];
            if (!empty($data)) {
                /** Need data on this function, below in mention.
                 * entityType,referenceId, parameterKey
                 * like this getQuestionnaireField("entityType","referenceId","parameterKey")
                 **/

                // get User Type
                $templateCustomField = QuestionnaireField::getAlQuestionnaireField("questionnaireTemplate", $data->questionnaireTemplateId);
                QuestionnaireField::getQuestionnaireField("questionnaireTemplate", $data->questionnaireTemplateId, "responseShow");
                $questionnaireFieldForSlot = QuestionnaireField::getQuestionnaireField("questionnaireTemplate", $data->questionnaireTemplateId, "slotTypeId");
                $questionnaireFieldForUserType = QuestionnaireField::getQuestionnaireField("questionnaireTemplate", $data->questionnaireTemplateId, "userTypeId");
                $questionnaireFieldForScoreType = QuestionnaireField::getQuestionnaireField("questionnaireTemplate", $data->questionnaireTemplateId, "scoreTypeId");
                if ($questionnaireFieldForUserType) {
                    $objData = $data->toArray();
                    if (isset($data->questionnaireTemplate->templateType->name)) {
                        $objData["templateType"] = $data->questionnaireTemplate->templateType->name;
                    }
                    $objData["scoreTypeId"] = $questionnaireFieldForScoreType->parameterValue;
                    $objData["scoreType"] = $questionnaireFieldForScoreType->getOptionName->name;

                    $objData["slotTypeId"] = $questionnaireFieldForSlot->parameterValue;
                    $objData["slotType"] = $questionnaireFieldForSlot->getOptionName->name;

                    $objData["userTypeId"] = $questionnaireFieldForUserType->parameterValue;
                    $objData["userType"] = $questionnaireFieldForUserType->getOptionName->name;

                    // get fillup by
                    if ($data->entityType == "246") {
                        // for staff
                        $staff = Staff::where("id", $data->referenceId)->first();
                        if (isset($staff->udid)) {
                            $objData["fillUpUser"] = $staff->firstName . " " . $staff->lastName;
                            $objData["fillUpUserId"] = $staff->udid;
                        }
                    } elseif ($data->entityType == "247") {
                        // for Patient
                        $patient = Patient::where("id", $data->referenceId)->first();
                        if (isset($patient->udid)) {
                            $objData["fillUpUser"] = $patient->firstName . " " . $patient->lastName;
                            $objData["fillUpUserId"] = $patient->udid;
                        }

                    }

                    if (isset($data->createdBy)) {
                        $user = User::where("id", $data->createdBy)->first();
                        if (isset($user->udid)) {
                            if ($user->roleId == "3" || $user->roleId == "1") {
                                // for staff
                                $staff = Staff::where("userId", $user->id)->first();
                                if (isset($staff->udid)) {
                                    $objData["assignBy"] = $staff->firstName . " " . $staff->lastName;
                                    $objData["assignById"] = $staff->udid;
                                }
                            } elseif ($user->roleId == "4") {
                                // for Patient
                                $patient = Patient::where("userId", $user->id)->first();
                                if (isset($patient->udid)) {
                                    $objData["assignBy"] = $patient->firstName . " " . $patient->lastName;
                                    $objData["assignById"] = $patient->udid;
                                }
                            }
                        }
                    }

                    if (!empty($templateCustomField)) {
                        $dataObj = [];
                        foreach ($templateCustomField as $v) {
                            $dataObj[$v->parameterKey] = $v->parameterValue;
                        }
                        $objData["questionnaireCustomField"] = $dataObj;
                    }
                    // get clientQuestionnaireTemplate id existing fillup response
                    $clientQuestionnaireTemplate = ClientQuestionnaireTemplate::where("clientQuestionnaireAssignId", $data->clientQuestionnaireAssignId)->where("isActive", "1")->latest("createdAt")->first();

                    // get score
                    if (isset($clientQuestionnaireTemplate->clientFillUpQuestionnaireId)) {
                        $objData["score"] = $this->getQuestionnaireTemplateScore($clientQuestionnaireTemplate->udid);
                    } else {
                        $objData["score"] = "";
                    }

                    $assignSection = QuestionnaireQuestion::leftjoin("questionnaireSections", function ($join) {
                        $join->on('questionnaireQuestions.referenceId', '=', 'questionnaireSections.questionnaireSectionId');
                    })
                        ->select("questionnaireQuestions.*", "questionnaireSections.sectionName", "questionnaireSections.udid as sectionId", "questionnaireSections.questionnaireSectionId")
                        ->where("questionnaireQuestions.questionnaireTempleteId", $data->questionnaireTemplateId)
                        ->where("questionnaireQuestions.entityType", "questionnaireSection")
                        ->get();

                    $objData["assignSection"] = $assignSection->toArray();
                    $i = 0;
                    // get total assign question in this template.
                    $getAllQuestion = QuestionnaireQuestion::join("questionSections", "questionnaireQuestions.referenceId", "=", "questionSections.questionnaireSectionId")
                        ->select("questionSections.*")
                        ->where("questionnaireQuestions.questionnaireTempleteId", $data->questionnaireTemplateId)
                        ->where("questionnaireQuestions.entityType", "questionnaireSection")
                        ->get();
                    // get total no of fillup question answer.
                    $fillUpQuestion = QuestionnaireQuestion::join("questionSections", "questionnaireQuestions.referenceId", "=", "questionSections.questionnaireSectionId")
                        ->join("clientFillUpQuestionnaireQuestions", function ($join) {
                            $join->on("clientFillUpQuestionnaireQuestions.questionnaireQuestionId", "=", "questionSections.questionId");
                            $join->on("clientFillUpQuestionnaireQuestions.referenceId", "=", "questionSections.questionnaireSectionId");
                        })
                        ->select("clientFillUpQuestionnaireQuestions.*")
                        ->where("questionnaireQuestions.questionnaireTempleteId", $data->questionnaireTemplateId)
                        ->where("questionnaireQuestions.entityType", "questionnaireSection")
                        ->where("clientFillUpQuestionnaireQuestions.isActive", 1)
                        ->get();
                    foreach ($assignSection as $sections) {
                        $questions = QuestionSection::with("questionsDataType", "questionOption");
                        $questions->join("questions", "questions.questionId", "=", "questionSections.questionId");
                        $questions->select("questionSections.*", "questions.udid as questionUdid", "questions.question", "questions.dataTypeId", "questions.questionType");
                        $questions->where("questionnaireSectionId", $sections->questionnaireSectionId);
                        $questions->where("questions.parent", "0");
                        $questions->where("questionSections.isActive", "1");
                        $questions = $questions->get();
                        $objQuestion = [];
                        $k = 0;
                        $answer = "";
                        foreach ($questions as $question) {
                            // get latest existing fillup response answer
                            if (isset($clientQuestionnaireTemplate->clientFillUpQuestionnaireId)) {
                                // getting filled question using clientQuestionnaireTemplate id and question id
                                if (isset($clientQuestionnaireTemplate->clientFillUpQuestionnaireId)) {
                                    $clientQuestionResponse = ClientQuestionResponse::where("clientFillUpQuestionnaireId", $clientQuestionnaireTemplate->clientFillUpQuestionnaireId)
                                        ->where("isActive", "1")
                                        ->where("questionnaireQuestionId", $question->questionId)
                                        ->first();
                                }
                                $answer = null;
                                // if question is filled getting filled answer.
                                if (isset($clientQuestionResponse->clientFillupQuestionnaireQuestionId)) {
                                    if (@$question->dataTypeId && $question->dataTypeId == "244") {
                                        $cleintQuestionAns = ClientResponseAnswer::where("clientFillupQuestionnaireQuestionId", $clientQuestionResponse->clientFillupQuestionnaireQuestionId)->where("isActive", "1")->get();
                                        if (!empty($cleintQuestionAns)) {
                                            $answer = array();
                                            foreach ($cleintQuestionAns as $vall) {
                                                $answer[] = $vall->response;
                                            }
                                        }
                                    } else {
                                        $cleintQuestionAns = ClientResponseAnswer::where("clientFillupQuestionnaireQuestionId", $clientQuestionResponse->clientFillupQuestionnaireQuestionId)->where("isActive", "1")->first();
                                        if (isset($cleintQuestionAns->response)) {
                                            $answer = $cleintQuestionAns->response;
                                        }
                                    }
                                }
                            }
                            $objQuestion[$k]["id"] = $question->questionUdid;
                            $objQuestion[$k]["questionId"] = $question->questionId;
                            $objQuestion[$k]["question"] = $question->question;
                            $objQuestion[$k]["dataTypeId"] = $question->dataTypeId;
                            if (@$question->questionType) {
                                $objQuestion[$k]["questionTypeId"] = $question->questionType;
                                $g = GlobalCode::where("id", $question->questionType)->first();
                                if (isset($g->name)) {
                                    $objQuestion[$k]["questionType"] = $g->name;
                                }
                            }
                            $objQuestion[$k]["dataType"] = $question->questionsDataType->name;
                            $objQuestion[$k]["answerFillUp"] = @$answer ?? "";
                            if (isset($question->questionOption) && !empty($question->questionOption)) {
                                $j = 0;
                                foreach ($question->questionOption as $opt) {
                                    $objQuestion[$k]["options"][$j] = $this->getQuestionOption($opt, $clientQuestionnaireTemplate);
                                    $j++;
                                }
                            }
                            // $objQuestion[$k]["questionOption"] = fractal()->collection($question->questionOption)->transformWith(new QuestionOptionTransformer())->serializeWith(new \Spatie\Fractalistic\ArraySerializer())->toArray();
                            $k++;
                        }
                        $objData["assignSection"][$i]["questions"] = $objQuestion;
                        $i++;
                    }
                }
            }
            return ["data" => $objData];
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function getQuestionOption($opt, $clientQuestionnaireTemplate)
    {
        try {
            if ($opt->questionOptionId) {
                $questionIdArr = [];
                $question = [];
                if (isset($opt->assignQuestion) && !empty($opt->assignQuestion)) {
                    foreach ($opt->assignQuestion as $q) {
                        $questionIdArr[] = $q->questionId;
                    }
                    if (!empty($questionIdArr)) {
                        $question = Question::whereIn("questionId", $questionIdArr)
                            ->where("isActive", 1)->get();
                    }
                }
                $questionnaireFieldForAction = QuestionnaireField::getQuestionnaireField("questionOption", $opt->questionOptionId, "optionAction");
            } else {
                $questionnaireFieldForAction = "";
                $question = [];
            }
            $data = [
                'id' => $opt->udid,
                'optionId' => $opt->questionOptionId,
                'option' => $opt->options,
                'optionAction' => @$questionnaireFieldForAction ?: '',
                'defaultOption' => $opt->defaultOption,
                'answer' => $opt->answer,
                'score' => $opt->score,
                'program' => $opt->program ? fractal()->collection($opt->program)->transformWith(new QuestionOptionProgramTransformer())->serializeWith(new \Spatie\Fractalistic\ArraySerializer())->toArray() : '',
                // 'question'=> $question?fractal()->collection($question)->transformWith(new QuestionTransformer())->serializeWith(new \Spatie\Fractalistic\ArraySerializer())->toArray():[],
            ];

            $i = 0;
            foreach ($question as $q) {
                $data["question"][$i] = $this->getQuestion($q, $clientQuestionnaireTemplate);
                $i++;
            }
            return $data;
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public static function getAllCustomOption($optionObjaRR, $sectionId, $editType, $clientQuestionnaireTemplate): ?array
    {
        try {
            if (isset($optionObjaRR->dataObj)) {
                $optionData = json_decode($optionObjaRR->dataObj);
                $optionArr = [];
                $questionnaireFieldForAction = "";
                if (!empty($optionData->option)) {
                    $i = 0;
                    foreach ($optionData->option as $data) {
                        if (isset($data->id)) {
                            // from assignQuestionOPtion
                            $questionOption = QuestionOption::where("udid", $data->id)->first();
                            if (isset($questionOption->udid)) {
                                $questionnaireFieldForAction = QuestionnaireField::getQuestionnaireField("questionOption", $questionOption->questionOptionId, "optionAction");
                                $questionObj = [];
                                $optionId = $questionOption->questionOptionId;
                                $assignQuestion = $questionOption->assignQuestion->toArray();
                                $questionIdArr = [];
                                if (isset($questionOption->assignQuestion) && !empty($assignQuestion)) {
                                    foreach ($questionOption->assignQuestion as $q) {
                                        $questionIdArr[] = $q->questionId;
                                    }
                                }

                                if (!empty($questionIdArr)) {
                                    $questionObjAr = Question::whereIn("questionId", $questionIdArr);
                                    if ($sectionId) {
                                        $questionObjAr->with('questionOption.assignQuestion', function ($q) use ($sectionId) {
                                            $q->where("sectionId", $sectionId);
                                        });
                                    }
                                    $questionObjAr = $questionObjAr->where("isActive", 1)->get();
                                    $questionObj = ClientQuestionnaireService::getAllQuestion($optionId, $questionObjAr, $sectionId, $editType, $clientQuestionnaireTemplate);
                                }
                            }
                        }

                        if (!empty($data->program)) {
                            $program = QuestionChangeService::getCustomProgram($data->program);
                        } else {
                            $program = "";
                        }

                        $optionArr[$i] = [
                            'id' => $data->id,
                            'sectionId' => $sectionId,
                            'optionId' => $optionId,
                            'optionAction' => $questionnaireFieldForAction ? $questionnaireFieldForAction : '',
                            'option' => $data->labelName,
                            'defaultOption' => $data->defaultOption,
                            'answer' => $data->answer,
                            'score' => (!empty($data->labelScore)) ? $data->labelScore : '',
                            'program' => (!empty($program)) ? $program : [],
                            'question' => $questionObj ? $questionObj : [],
                        ];

                        $i++;
                    }
                }
                return $optionArr;
            }
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function getQuestion($data, $clientQuestionnaireTemplate): ?array
    {
        try {
            $dataObj = array();
            if (isset($data->questionnaireField)) {
                foreach ($data->questionnaireField as $v) {
                    $dataObj[$v["parameterKey"]] = $v["parameterValue"];
                }
            }

            if (isset($clientQuestionnaireTemplate->clientFillUpQuestionnaireId)) {

                // getting filled question using clientQuestionnaireTemplate id and question id
                if (isset($clientQuestionnaireTemplate->clientFillUpQuestionnaireId)) {
                    $clientQuestionResponse = ClientQuestionResponse::where("clientFillUpQuestionnaireId", $clientQuestionnaireTemplate->clientFillUpQuestionnaireId)
                        ->where("isActive", "1")
                        ->where("questionnaireQuestionId", $data->questionId)
                        ->first();
                }

                $answer = null;
                // if question is filled getting filled answer.
                if (isset($clientQuestionResponse->clientFillupQuestionnaireQuestionId)) {
                    if (isset($data->dataTypeId) && $data->dataTypeId == "244") {
                        $cleintQuestionAns = ClientResponseAnswer::where("clientFillupQuestionnaireQuestionId", $clientQuestionResponse->clientFillupQuestionnaireQuestionId)->where("isActive", "1")->get();
                        if (!empty($cleintQuestionAns)) {
                            $answer = array();
                            foreach ($cleintQuestionAns as $vall) {
                                $answer[] = $vall->response;
                            }
                        }
                    } else {
                        $cleintQuestionAns = ClientResponseAnswer::where("clientFillupQuestionnaireQuestionId", $clientQuestionResponse->clientFillupQuestionnaireQuestionId)->where("isActive", "1")->first();
                        if (isset($cleintQuestionAns->response)) {
                            $answer = $cleintQuestionAns->response;
                        }
                    }
                }
            }

            $dataObjArr = [
                'id' => $data->udid,
                'questionId' => $data->questionId,
                'question' => $data->question,
                'dataTypeId' => $data->dataTypeId,
                'dataType' => (!empty($data->questionsDataType)) ? $data->questionsDataType->name : '',
                'questionTypeId' => (!empty($data->questionType)) ? $data->questionType : '',
                'questionType' => (!empty($data->questionsType)) ? $data->questionsType->name : '',
                'isActive' => $data->isActive ? True : False,
                'score' => (!empty($data->score)) ? $data->score->score : '',
                'questionnaireCustomField' => $dataObj
            ];
            $dataObjArr["answerFillUp"] = @$answer ?? "";

            $i = 0;
            foreach ($data->questionOption as $opt) {
                $dataObjArr["options"][$i] = $this->getQuestionOption($opt, $clientQuestionnaireTemplate);
                $i++;
            }
            return $dataObjArr;
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function getAllQuestion($optionId, $dataArr, $sectionId, $editType, $clientQuestionnaireTemplate): ?array
    {
        try {
            $objQuestionArr = array();
            foreach ($dataArr as $data) {
                $dataObj = array();
                if (isset($data->questionnaireField)) {
                    foreach ($data->questionnaireField as $v) {
                        $dataObj[$v["parameterKey"]] = $v["parameterValue"];
                    }
                }

                $isAssign = false;
                if (isset($data->questionId)) {
                    $questionSection = QuestionSection::where("questionId", $data->questionId)->first();
                    if (isset($questionSection->questionId)) {
                        $isAssign = true;
                    }
                }

                if (isset($clientQuestionnaireTemplate->clientFillUpQuestionnaireId)) {

                    // getting filled question using clientQuestionnaireTemplate id and question id
                    if (isset($clientQuestionnaireTemplate->clientFillUpQuestionnaireId)) {
                        $clientQuestionResponse = ClientQuestionResponse::where("clientFillUpQuestionnaireId", $clientQuestionnaireTemplate->clientFillUpQuestionnaireId)
                            ->where("isActive", "1")
                            ->where("questionnaireQuestionId", $data->questionId)
                            ->first();
                    }

                    $answer = null;
                    // if question is filled getting filled answer.
                    if (isset($clientQuestionResponse->clientFillupQuestionnaireQuestionId)) {
                        if (isset($data->dataTypeId) && $data->dataTypeId == "244") {
                            $cleintQuestionAns = ClientResponseAnswer::where("clientFillupQuestionnaireQuestionId", $clientQuestionResponse->clientFillupQuestionnaireQuestionId)->where("isActive", "1")->get();
                            if (!empty($cleintQuestionAns)) {
                                $answer = array();
                                foreach ($cleintQuestionAns as $vall) {
                                    $answer[] = $vall->response;
                                }
                            }
                        } else {
                            $cleintQuestionAns = ClientResponseAnswer::where("clientFillupQuestionnaireQuestionId", $clientQuestionResponse->clientFillupQuestionnaireQuestionId)->where("isActive", "1")->first();
                            if (isset($cleintQuestionAns->response)) {
                                $answer = $cleintQuestionAns->response;
                            }
                        }
                    }
                }

                // $questionUpdate = QuestionChanges::where("questionId",$data->questionId);
                //                     if($parentId){
                //                         $questionUpdate->where("parentId",$parentId);
                //                     }
                //                     if($childId){
                //                         $questionUpdate->where("childId",$childId);
                //                     }
                //                     $questionUpdate->where("entityType",$editType);

                $questionOption = $data->questionOption;

                $questionUpdate = QuestionChanges::where("questionId", $data->questionId);
                if (isset($sectionId) && !empty($sectionId)) {
                    $questionUpdate->where("sectionId", $sectionId);
                }

                if (isset($optionId) && !empty($optionId)) {
                    $questionUpdate->where("childId", $optionId);
                }
                $questionUpdate->where("entityType", $editType);
                $questionUpdate = $questionUpdate->first();

                $dataObj = "";
                if (isset($questionUpdate->dataObj)) {
                    $dataObj = json_decode($questionUpdate->dataObj);
                }

                $dataType = [];
                if (isset($dataObj->dataTypeId)) {
                    $dataType = GlobalCode::where("id", $dataObj->dataTypeId)->first();
                }

                $questionType = [];
                if (isset($dataObj->questionType)) {
                    $questionType = GlobalCode::where("id", $dataObj->questionType)->first();
                }

                $optionObjaRR = [];
                $optionCustomData = [];
                if ($sectionId) {
                    $optionObjaRR = QuestionChanges::where("sectionId", $sectionId);
                    $optionObjaRR->where("questionId", $data->questionId);
                    if ($optionId > 0) {
                        $optionObjaRR->where("childId", $optionId);
                    }
                    $optionObjaRR->where("entityType", "templateOption");
                    $optionObjaRR = $optionObjaRR->first();
                }

                if (isset($optionObjaRR->udid)) {
                    $optionCustomData = ClientQuestionnaireService::getAllCustomOption($optionObjaRR, $sectionId, $editType, $clientQuestionnaireTemplate);
                } else {
                    $optionObjData = QuestionChangeService::getAllOptionQuestion($questionOption, $sectionId, $editType);
                }

                $objQuestionArr[] = [
                    'id' => $data->udid,
                    'questionId' => $data->questionId,
                    'optionId' => $optionId,
                    'question' => (!empty($dataObj)) ? $dataObj->question : $data->question,
                    'isAssign' => $isAssign,
                    'dataTypeId' => (!empty($dataObj)) ? $dataObj->dataTypeId : $data->dataTypeId,
                    'dataType' => (!empty($dataType)) ? $dataType->name : $data->questionsDataType->name,
                    'questionTypeId' => (!empty($dataObj->questionType)) ? $dataObj->questionType : $data->questionType,
                    'questionType' => (isset($questionType->name)) ? $questionType->name : (isset($data->questionsType->name)) ? $data->questionsType->name : '',
                    'isActive' => $data->isActive ? True : False,
                    'score' => (!empty($data->score)) ? $data->score->score : '',
                    // 'options'=> $data->questionOption?fractal()->collection($questionOption)->transformWith(new QuestionOptionTransformer())->serializeWith(new \Spatie\Fractalistic\ArraySerializer())->toArray():[],
                    'options' => $optionObjaRR ? $optionCustomData : $optionObjData,
                    // 'options'=> $optionObjData,
                    'questionnaireCustomField' => $dataObj,
                    'tags' => isset($data->tags) ? $data->tags : [],
                    "answerFillUp" => isset($answer) ? $answer : ""
                ];
            }
            return $objQuestionArr;
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function questionnaireTemplateByUser($request, $id)
    {
        try {
            if (!$id) {
                $userId = Auth::id();
                $role = Auth::user()->roleId;
                $data = ClientQuestionnaireTemplate::with("questionnaireTemplate", "templateType");
                $data->select("clientFillUpQuestionnaire.*");
                $data->leftJoin('questionnaireTemplates', 'questionnaireTemplates.questionnaireTemplateId', '=', 'clientFillUpQuestionnaire.questionnaireTemplateId');
                if ($role == 1 || $role == 3) {
                    if ($request->entityType == "246" && !empty($request->referenceId)) {
                        $staff = Staff::where("udid", $request->referenceId)->first();
                        if (isset($staff->id)) {
                            $data->where("referenceId", $staff->id);
                        } else {
                            return response()->json(['message' => "user id invalid."], 404);
                        }
                    } elseif ($request->entityType == "247" && !empty($request->referenceId)) {
                        $patient = Patient::where("udid", $request->referenceId)->first();
                        if (isset($patient->id)) {
                            $data->where("referenceId", $patient->id);
                        } else {
                            return response()->json(['message' => "user id invalid."], 404);
                        }
                    } else {
                        if ($request->referenceId) {
                            $data->where("referenceId", $request->referenceId);
                        }

                        if ($request->entityType) {
                            $data->where("entityType", $request->entityType);
                        }
                    }

                } elseif ($role == 4) {
                    if ($request->entityType == "247" && !empty($request->referenceId)) {
                        $patient = Patient::where("udid", $request->referenceId)->first();
                        if (isset($patient->id)) {
                            $data->where("referenceId", $patient->id);
                        } else {
                            return response()->json(['message' => "user id invalid."], 404);
                        }
                    } else {
                        $patient = Patient::where("userid", $userId)->first();
                        $data->where("referenceId", $patient->id);
                    }
                }

                if ($request->status) {
                    $status = ucfirst($request->status);
                    $data->where("clientFillUpQuestionnaire.status", $status);
                }

                if ($request->search || $request->templateTypeId) {
                    $data->orWhere(function ($query) use ($request) {
                        $query->WhereHas('questionnaireTemplate', function ($q) use ($request) {
                            if ($request->search) {
                                $q->where("templateName", 'LIKE', "%" . $request->search . "%");
                            }

                            if ($request->templateTypeId) {
                                $q->where("templateTypeId", $request->templateTypeId);
                            }
                        });
                    });
                }


                if ($request->orderField === 'templateName') {
                    $data->orderBy($request->orderField, $request->orderBy);
                } elseif ($request->orderField === 'templateType') {
                    $data->join('globalCodes', 'globalCodes.id', '=', 'questionnaireTemplates.templateTypeId')
                        ->orderBy('globalCodes.name', $request->orderBy);
                } else {
                    $data->orderBy('clientFillUpQuestionnaire.createdAt', 'Desc');
                }
                $data = $data->paginate(env('PER_PAGE', 20));

                return fractal()->collection($data)->transformWith(new ClientQuestionnaireTransformer())->paginateWith(new IlluminatePaginatorAdapter($data))->toArray();
            }
            $data = ClientQuestionnaireTemplate::with("questionnaireTemplate", "templateType", "clientQuestionResponse.questionnaireQuestion")
                ->where("udid", $id)->first();
            if (empty($data)) {
                return response()->json(['message' => "template id invalid."], 400);
            }
            $data["id"] = $id;
            return fractal()->item($data)->transformWith(new ClientQuestionnaireTransformer())->toArray();
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }

    }

    public function getQuestionnaireScore($request, $id)
    {
        try {
            $userId = Auth::id();
            $role = Auth::user()->roleId;
            $template = ClientQuestionnaireTemplate::where("udid", $id)->first();
            if (isset($template->questionnaireTemplateId)) {
                $templateScoreType = QuestionnaireField::getQuestionnaireField("questionnaireTemplate", $template->questionnaireTemplateId, "scoreTypeId");
                if ($templateScoreType->parameterValue == "339") {
                    //NoScore;
                    return [
                        "data" => [
                            "program" => "No Score",
                            "score" => "0"
                        ]
                    ];
                }
                if ($role == 1 || $role == 3) {
                    $staff = Staff::where("userId", $userId)->first();
                    $userIds = $staff->id;
                } else {
                    $patient = Patient::where("userId", $userId)->first();
                    $userIds = $patient->id;
                }

                $clientQuestionnaire = ClientQuestionnaireTemplate::where('questionnaireTemplateId', $template->questionnaireTemplateId)
                    // ->where("referenceId",$userIds)
                    ->orderBy("clientFillUpQuestionnaireId", "desc")
                    ->first();


                $ids = $clientQuestionnaire->clientFillUpQuestionnaireId;
                $data = DB::select("CALL getQuestionnaireResponse('" . $ids . "')");

                if (count($data) > 0) {
                    $pScoreArr = [];
                    $pname = "";
                    $pscore = 0;
                    $questionnaireData = array();
                    $i = 0;
                    foreach ($data as $d) {
                        // $questionnaireQuestion = QuestionnaireQuestion::select('udid','questionnaireQuestionId','questionId','isActive')
                        // ->where("questionnaireQuestionId",$d->questionnaireQuestionId)
                        // ->where("isActive",1)
                        // ->with(['question', 'question.questionsDataType'])
                        // ->with(['question.questionOption' => function($q) {
                        //     $q->where('defaultOption', 1); // '=' is optional
                        // }])
                        // ->get();
                        $questionScoreType = QuestionnaireField::getQuestionnaireField("questions", $d->questionnaireQuestionId, "scoreTypeId");
                        if (isset($questionScoreType->parameterValue)) {
                            if ($questionScoreType->parameterValue == "333") {
                                $typeOfScoreName = "GeneralScore";
                            } elseif ($questionScoreType->parameterValue == "332") {
                                $typeOfScoreName = "ProgramScore";
                            } elseif ($questionScoreType->parameterValue == "339") {
                                $typeOfScoreName = "NoScore";
                                continue;
                            }
                        } else {
                            $typeOfScoreName = "others";
                        }

                        // print_r($d)."--";

                        if ($d->dataType == "242") {
                            // echo $i;
                            $question = Question::where('questionId', $d->questionnaireQuestionId);
                            $question = $question->first();

                            if (isset($question->udid)) {
                                $correctScore = QuestionnaireField::getQuestionnaireField("questions", $d->questionnaireQuestionId, "correctScore");
                                $incorrectScore = QuestionnaireField::getQuestionnaireField("questions", $d->questionnaireQuestionId, "incorrectScore");
                                $correctAnwer = QuestionnaireField::getQuestionnaireField("questions", $d->questionnaireQuestionId, "correctAnwer");
                                // print_r($templateScoreTypeId->toArray());
                            }
                            // print_r($questionScoreType->toArray());
                            // die;
                            if ($questionScoreType->parameterValue == "333") {
                                $pname = $typeOfScoreName;
                                if ($correctAnwer == $d->response) {
                                    if (isset($correctScore->parameterValue) && $correctScore->parameterValue > 0) {
                                        $pscore = $correctScore->parameterValue;
                                    } else {
                                        $pscore = 0;
                                    }
                                } else {
                                    if (isset($incorrectScore->parameterValue) && $incorrectScore->parameterValue > 0) {
                                        $pscore = $incorrectScore->parameterValue;
                                    } else {
                                        $pscore = 0;
                                    }
                                }
                            }

                            if ($questionScoreType->parameterValue == "332") {
                                $pname = $typeOfScoreName;

                                if ($correctAnwer == $d->response) {
                                    if (isset($correctScore->parameterValue) && $correctScore->parameterValue > 0) {
                                        $pscore = $correctScore->parameterValue;
                                    } else {
                                        $pscore = 0;
                                    }
                                } else {
                                    if (isset($incorrectScore->parameterValue) && $incorrectScore->parameterValue > 0) {
                                        $pscore = $incorrectScore->parameterValue;
                                    } else {
                                        $pscore = 0;
                                    }
                                }
                            }
                        } elseif ($d->dataType == "241") {
                            $programScore = ClientResponseAnswer::with("programScore")
                                ->where("dataType", $d->dataType)
                                ->where("clientFillupQuestionnaireQuestionId", $d->clientFillupQuestionnaireQuestionId)
                                ->first();

                            if (!empty($programScore)) {
                                $programScore = $programScore->toArray();
                                $pname = $typeOfScoreName;
                                if (isset($programScore["program_score"]["score"])) {
                                    $pscore = $programScore["program_score"]["score"];
                                } else {
                                    $pscore = 0;
                                }
                            }
                        } else {
                            $pscore = 0;
                            if (isset($questionScoreType->parameterValue)) {
                                if ($questionScoreType->parameterValue == "333") {
                                    $pname = $typeOfScoreName;
                                    $questionScore = QuestionOption::with("score")
                                        ->where("udid", $d->response)->first();
                                    if (isset($questionScore->score)) {
                                        $pscore = $questionScore->score->score;
                                    } else {
                                        $pscore = 0;
                                    }
                                } elseif ($questionScoreType->parameterValue == "332") {
                                    $programScore = ClientResponseProgram::with("programScore")
                                        ->select("clientResponsePrograms.*", "programs.name as programName", "programs.typeId", "programs.providerLocationId")
                                        ->join("programs", "programs.id", "clientResponsePrograms.program")
                                        ->where("clientResponseAnswerId", $d->clientResponseAnswerId)
                                        ->first();

                                    // print_r($programScore->toArray());

                                    if (!empty($programScore)) {
                                        $programScore = $programScore->toArray();
                                        // print_r($programScore["program"]["name"]);
                                        if (isset($programScore["programName"])) {
                                            $pname = $programScore["programName"];
                                        }
                                        if (isset($programScore["program_score"]["score"])) {
                                            $pscore = $programScore["program_score"]["score"];
                                        } else {
                                            $pscore = 0;
                                        }
                                    }
                                }
                            }
                        }
                        $pScoreArr["data"][] = ["program" => $pname, "score" => $pscore];
                        $i++;
                    }
                }
                $data = $pScoreArr;
                $byGroup["groupBy"] = $this->group_by($data);
                $neArr = array();
                $i = 0;
                foreach ($byGroup["groupBy"] as $v) {
                    $neArr["data"][$i]["program"] = $v["program"];
                    $neArr["data"][$i]["score"] = $v["score"];
                    $i++;
                }
                // $result = array_merge($data,$neArr);

                // $result = array_merge($neArr,$questionnaireData);
                return $neArr;
                // ClientQuestionResponse::with("clientResponseAnswer")
                // ->where()
                // print_r($data);
                // dd($clientQuestionnaire);
            }
            return response()->json(['message' => "template id invalid."], 400);
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function getQuestionnaireTemplateScore($clientFillUpQuestionnaireId)
    {
        try {
            $userId = Auth::id();
            $role = Auth::user()->roleId;
            $template = ClientQuestionnaireTemplate::where("udid", $clientFillUpQuestionnaireId)->first();
            if (isset($template->questionnaireTemplateId)) {
                $templateScoreType = QuestionnaireField::getQuestionnaireField("questionnaireTemplate", $template->questionnaireTemplateId, "scoreTypeId");
                if ($templateScoreType->parameterValue == "339") {
                    //NoScore;
                    return [
                        "data" => [
                            "program" => "No Score",
                            "score" => "0"
                        ]
                    ];
                }
                if ($role == 1 || $role == 3) {
                    $staff = Staff::where("userId", $userId)->first();
                    $userIds = $staff->id;
                } else {
                    $patient = Patient::where("userId", $userId)->first();
                    $userIds = $patient->id;
                }
                $clientQuestionnaire = ClientQuestionnaireTemplate::where('questionnaireTemplateId', $template->questionnaireTemplateId)
                    // ->where("referenceId",$userIds)
                    ->orderBy("clientFillUpQuestionnaireId", "desc")
                    ->first();
                $ids = $clientQuestionnaire->clientFillUpQuestionnaireId;
                $data = DB::select("CALL getQuestionnaireResponse('" . $ids . "')");
                // print_r($data);
                // die;
                if (count($data) > 0) {
                    $pScoreArr = [];
                    $customProObj = [];
                    $output = [];
                    $pname = "";
                    $pscore = 0;
                    $i = 0;
                    foreach ($data as $d) {
                        $questionScoreType = QuestionnaireField::getQuestionnaireField("questions", $d->questionnaireQuestionId, "scoreTypeId");
                        if (isset($questionScoreType->parameterValue)) {
                            if ($questionScoreType->parameterValue == "333") {
                                $typeOfScoreName = "GeneralScore";
                            } elseif ($questionScoreType->parameterValue == "332") {
                                $typeOfScoreName = "ProgramScore";
                            } elseif ($questionScoreType->parameterValue == "339") {
                                $typeOfScoreName = "NoScore";
                                continue;
                            }
                        } else {
                            $typeOfScoreName = "others";
                        }

                        if ($d->dataType == "242") {
                            // echo $i;
                            $pscore = 0;
                            $question = Question::where('questionId', $d->questionnaireQuestionId);
                            $question = $question->first();

                            if (isset($question->udid)) {
                                $correctScore = QuestionnaireField::getQuestionnaireField("questions", $d->questionnaireQuestionId, "correctScore");
                                $incorrectScore = QuestionnaireField::getQuestionnaireField("questions", $d->questionnaireQuestionId, "incorrectScore");
                                $correctAnwer = QuestionnaireField::getQuestionnaireField("questions", $d->questionnaireQuestionId, "correctAnwer");
                                // print_r($templateScoreTypeId->toArray());
                            }
                            // print_r($questionScoreType->toArray());
                            // die;
                            if (isset($questionScoreType->parameterValue) && $questionScoreType->parameterValue == "333") {
                                $pname = $typeOfScoreName;
                                if ($correctAnwer == $d->response) {
                                    if (isset($correctScore->parameterValue) && $correctScore->parameterValue > 0) {
                                        $pscore = $correctScore->parameterValue;
                                    } else {
                                        $pscore = 0;
                                    }
                                } else {
                                    if (isset($incorrectScore->parameterValue) && $incorrectScore->parameterValue > 0) {
                                        $pscore = $incorrectScore->parameterValue;
                                    } else {
                                        $pscore = 0;
                                    }
                                }
                            }

                            if (isset($questionScoreType->parameterValue) && $questionScoreType->parameterValue == "332") {
                                $pname = $typeOfScoreName;

                                if ($correctAnwer == $d->response) {
                                    if (isset($correctScore->parameterValue) && $correctScore->parameterValue > 0) {
                                        $pscore = $correctScore->parameterValue;
                                    } else {
                                        $pscore = 0;
                                    }
                                } else {
                                    if (isset($incorrectScore->parameterValue) && $incorrectScore->parameterValue > 0) {
                                        $pscore = $incorrectScore->parameterValue;
                                    } else {
                                        $pscore = 0;
                                    }
                                }
                            }
                        } elseif ($d->dataType == "241") {
                            $programScore = ClientResponseAnswer::with("programScore")
                                ->where("dataType", $d->dataType)
                                ->where("clientFillupQuestionnaireQuestionId", $d->clientFillupQuestionnaireQuestionId)
                                ->first();
                            if (!empty($programScore)) {
                                $programScore = $programScore->toArray();
                                $pname = $typeOfScoreName;
                                if (isset($programScore["program_score"]["score"])) {
                                    $pscore = $programScore["program_score"]["score"];
                                } else {
                                    $pscore = 0;
                                }
                            }
                        } else {
                            if (isset($questionScoreType->parameterValue)) {
                                $pscore = 0;
                                if ($questionScoreType->parameterValue == "333") {
                                    $pname = $typeOfScoreName;
                                    // $questionScore =  QuestionOption::with("score")
                                    // ->where("udid",$d->response)->first();
                                    // print_r($questionScore);
                                    // die;
                                    $questionScore = ClientQuestionScore::where("referenceId", $d->clientResponseAnswerId)
                                        ->where("isActive", 1)
                                        ->where("dataType", $d->dataType)
                                        ->first();

                                    if (isset($questionScore->score)) {
                                        $pscore = $questionScore->score;
                                    } else {
                                        $pscore = 0;
                                    }
                                } elseif ($questionScoreType->parameterValue == "332") {
                                    $programScore = ClientResponseProgram::with("programScore")
                                        ->select("clientResponsePrograms.*", "programs.name as programName", "programs.typeId", "programs.providerLocationId")
                                        ->join("programs", "programs.id", "clientResponsePrograms.program")
                                        ->where("clientResponseAnswerId", $d->clientResponseAnswerId)
                                        ->where("clientResponsePrograms.isActive", 1)
                                        ->get();
                                    // print_r($programScore);
                                    // print_r($programScore->toArray());
                                    // die;
                                    if (!empty($programScore)) {
                                        $programScore = $programScore->toArray();
                                        // print_r($programScore["program"]["name"]);
                                        foreach ($programScore as $pObj) {
                                            $proname = $pObj["programName"];

                                            if (isset($pObj["program_score"]["score"])) {
                                                $pscoreSum = $pObj["program_score"]["score"];
                                            } else {
                                                $pscoreSum = 0;
                                            }
                                            $customProObj[] = ["program" => $proname, "score" => $pscoreSum];

                                        }
                                    }
                                }
                            }
                        }
                        if ($pname != "") {
                            $pScoreArr["data"][] = ["program" => $pname, "score" => $pscore];
                        }
                        if (count($customProObj) > 0) {
                            if (isset($pScoreArr["data"])) {
                                $output["data"] = array_merge($pScoreArr["data"], $customProObj);
                            } else {
                                $output["data"] = $customProObj;
                            }
                        } else {
                            $output = $pScoreArr;
                        }
                        $i++;
                    }
                }
                $neArr = array();
                if (isset($output["data"])) {
                    $byGroup["groupBy"] = $this->group_by($output);
                    $i = 0;
                    foreach ($byGroup["groupBy"] as $v) {
                        if ($v["program"] != "") {
                            $neArr[$i]["program"] = $v["program"];
                            $neArr[$i]["score"] = $v["score"];
                        }
                        $i++;
                    }
                }
                return $neArr;
            }
            return [];
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    function group_by($array)
    {
        try {
            $result = array();
            foreach ($array["data"] as $key => $val) {
                if (!isset($result[$val['program']])) {
                    $result[$val['program']] = array
                    (
                        'program' => $val['program'],
                        'score' => $val['score']
                    );
                } else {
                    if (isset($val['score']) && $val['score'] > 0) {
                        $result[$val['program']]['score'] += $val['score'];
                    }
                }
            }
            return $result;
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function addQuestionnaireTemplateByUsersNew($request, $id)
    {
        try {
            $post = $request->all();
            $clientQuestionnaire = ClientQuestionnaireAssign::where('udid', $id)->first();
            $clientQuestionnaireAssignId = $clientQuestionnaire->clientQuestionnaireAssignId;
            if (isset($clientQuestionnaireAssignId) && isset($post)) {
                foreach ($post as $postArr) {
                    $question = Question::where('udid', $postArr["question"])->first();
                    $questionnaireResponse = [
                        'udid' => Str::uuid()->toString(), 'clientQuestionnaireAssignId' => $clientQuestionnaireAssignId,
                        'questionnaireQuestionId' => $question->questionId
                    ];
                    if (isset($postArr["sectionId"])) {
                        $questionnaireSection = QuestionnaireSection::where('udid', $postArr["sectionId"])->first();
                        if (isset($questionnaireSection->questionnaireSectionId)) {
                            $questionnaireResponse["referenceId"] = $questionnaireSection->questionnaireSectionId;
                            $questionnaireResponse["entityType"] = "questionnaireSection";
                        }
                    }
                    $responseId = ClientQuestionResponse::insertGetId($questionnaireResponse);
                    // $responseId = DB::select("CALL addClientQuestionnaireResponse('" . JSON_ENCODE($questionnaireResponse)  . "')");
                    if ($postArr["dataType"] == 243) {
                        $answer = $postArr["answer"];
                        if (!empty($answer)) {
                            $this->insertQuestionOption($postArr, $answer, $responseId, $clientQuestionnaireAssignId);
                        }
                    } elseif ($postArr["dataType"] == 241 || $postArr["dataType"] == 242) {
                        if (!empty($postArr["answer"])) {
                            $question = Question::where('udid', $postArr["question"])->first();
                            $getScore = QuestionScore::where('referenceId', $question->questionId)
                                ->where("entityType", "253")
                                ->first();
                            $questionScore = @$getScore->score ?? NULL;
                            $response = [
                                'udid' => Str::uuid()->toString(), 'clientQuestionnaireAssignId' => $clientQuestionnaireAssignId, 'clientFillupQuestionnaireQuestionId' => $responseId,
                                'dataType' => $postArr["dataType"], 'response' => $postArr["answer"]
                            ];
                            $responseAnswer = ClientResponseAnswer::insertGetId($response);
                            // $responseAnswer=DB::select("CALL addClientResponseAnswer('" . JSON_ENCODE($response)  . "')");
                            $programScore = ['udid' => Str::uuid()->toString(), 'clientQuestionnaireAssignId' => $clientQuestionnaireAssignId, 'dataType' => $postArr["dataType"], 'score' => $questionScore, 'referenceId' => $responseAnswer, 'entityType' => 256];
                            DB::select("CALL addClientQuestionScore('" . JSON_ENCODE($programScore) . "')");
                        }
                    } elseif ($postArr["dataType"] == 244) {
                        $answer = $postArr["answer"];
                        if (!empty($answer)) {
                            foreach ($answer as $value) {
                                if (!empty($value)) {
                                    $this->insertQuestionOption($postArr, $value, $responseId, $clientQuestionnaireAssignId);
                                }
                            }
                        }
                    }
                }
                return response()->json(['message' => trans('messages.createdSuccesfully')]);
            }
            return response()->json(['message' => "template id invalid."], 400);
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function addQuestionnaireTemplateByUsers($request, $id): ?\Illuminate\Http\JsonResponse
    {
        try {
            $post = $request->all();
            unset($post["provider"], $post["location"]);
            $clientQuestionnaire = ClientQuestionnaireAssign::with("clientQuestionnaireTemplate")->where('udid', $id)->orderBy("clientQuestionnaireAssignId", 'desc')->first();
            if (isset($clientQuestionnaire->clientQuestionnaireAssignId)) {
                if (isset($clientQuestionnaire->clientQuestionnaireTemplate->clientFillUpQuestionnaireId)) {
                    $clientFillUpQuestionnaireId = $clientQuestionnaire->clientQuestionnaireTemplate->clientFillUpQuestionnaireId;
                    $this->deleteQuestionnaireFillUpAnswer($clientFillUpQuestionnaireId);
                } else {
                    $template = QuestionnaireTemplate::where("questionnaireTemplateId", $clientQuestionnaire->questionnaireTemplateId)->first();
                    if (!empty($template)) {
                        $role = Auth::user()->roleId;
                        $userIdd = Auth::user()->id;
                        if ($role == 1 || $role == 3) {
                            $entitypeId = "246";
                            $staff = Staff::where("userId", $userIdd)->first();
                            $loginUserId = $staff->id;
                        } elseif ($role == 4) {
                            $entitypeId = "247";
                            $patient = Patient::where("userId", $userIdd)->first();
                            $loginUserId = $patient->id;
                        } else {
                            die("entityType Required!!!");
                        }
                        $obj = [
                            'udid' => Str::uuid()->toString(),
                            'clientQuestionnaireAssignId' => $clientQuestionnaire->clientQuestionnaireAssignId,
                            'questionnaireTemplateId' => $template->questionnaireTemplateId,
                            'referenceid' => $loginUserId,
                            'createdBy' => Auth::id(),
                            'entityType' => $entitypeId,
                            'status' => "Inprogress"
                        ];
                        $lastid = ClientQuestionnaireTemplate::insertGetId($obj);
                        $clientFillUpQuestionnaireId = $lastid;
                    }
                }
                if (isset($clientFillUpQuestionnaireId, @$post)) {
                    foreach ($post as $postArr) {
                        $question = Question::where('udid', $postArr["question"])->first();
                        $questionnaireResponse = [
                            'udid' => Str::uuid()->toString(), 'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId,
                            'questionnaireQuestionId' => $question->questionId
                        ];
                        if (isset($postArr["sectionId"])) {
                            $questionnaireSection = QuestionnaireSection::where('udid', $postArr["sectionId"])->first();
                            if (isset($questionnaireSection->questionnaireSectionId)) {
                                $questionnaireResponse["referenceId"] = $questionnaireSection->questionnaireSectionId;
                                $questionnaireResponse["entityType"] = "questionnaireSection";
                            }
                        }
                        $responseId = ClientQuestionResponse::insertGetId($questionnaireResponse);
                        // $responseId = DB::select("CALL addClientQuestionnaireResponse('" . JSON_ENCODE($questionnaireResponse)  . "')");

                        if ($postArr["dataType"] == 243) {
                            $answer = $postArr["answer"];
                            if (!empty($answer)) {
                                $this->insertQuestionOption($postArr, $answer, $responseId, $clientFillUpQuestionnaireId);
                            }
                        } elseif ($postArr["dataType"] == 241 || $postArr["dataType"] == 242) {
                            if (!empty($postArr["answer"])) {
                                $question = Question::where('udid', $postArr["question"])->first();
                                $getScore = QuestionScore::where('referenceId', $question->questionId)
                                    ->where("entityType", "253")
                                    ->first();
                                // echo $postArr["question"];
                                $questionScore = @$getScore->score ?? 0;
                                $response = [
                                    'udid' => Str::uuid()->toString(), 'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId, 'clientFillupQuestionnaireQuestionId' => $responseId,
                                    'dataType' => $postArr["dataType"], 'response' => $postArr["answer"]
                                ];
                                $responseAnswer = ClientResponseAnswer::insertGetId($response);
                                // $responseAnswer=DB::select("CALL addClientResponseAnswer('" . JSON_ENCODE($response)  . "')");
                                $programScore = ['udid' => Str::uuid()->toString(), 'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId, 'dataType' => $postArr["dataType"], 'score' => $questionScore, 'referenceId' => $responseAnswer, 'entityType' => 256];
                                DB::select("CALL addClientQuestionScore('" . JSON_ENCODE($programScore) . "')");
                            }
                        } elseif ($postArr["dataType"] == 244) {
                            $answer = $postArr["answer"];
                            if (!empty($answer)) {
                                foreach ($answer as $value) {
                                    if (!empty($value)) {
                                        $this->insertQuestionOption($postArr, $value, $responseId, $clientFillUpQuestionnaireId);
                                    }
                                }
                            }
                        }
                    }
                    /*
                    * update questionnaire status (Inprogress,Complete).
                    */
                    $this->questionnaireStatusUpdate($clientQuestionnaire->questionnaireTemplateId, $clientFillUpQuestionnaireId);
                    return response()->json(['message' => trans('messages.createdSuccesfully'), 'id' => $clientFillUpQuestionnaireId]);
                }
                return response()->json(['message' => "template id invalid."], 400);
            }
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function questionnaireStatusUpdate($questionnaireTemplateId, $clientFillUpQuestionnaireId): void
    {
        try {
            if ($questionnaireTemplateId) {
                // get total assign question in this template.
                $getAllQuestion = QuestionnaireQuestion::join("questionSections", "questionnaireQuestions.referenceId", "=", "questionSections.questionnaireSectionId")
                    ->select("questionSections.*")
                    ->where("questionnaireQuestions.questionnaireTempleteId", $questionnaireTemplateId)
                    ->where("questionnaireQuestions.entityType", "questionnaireSection")
                    ->where("questionSections.isActive", 1)
                    ->get();
                $totalQuestion = count($getAllQuestion);

                // get total no of fillup question answer.
                $fillUpQuestion = QuestionnaireQuestion::join("questionSections", "questionnaireQuestions.referenceId", "=", "questionSections.questionnaireSectionId")
                    ->join("clientFillUpQuestionnaireQuestions", function ($join) {
                        $join->on("clientFillUpQuestionnaireQuestions.questionnaireQuestionId", "=", "questionSections.questionId");
                        $join->on("clientFillUpQuestionnaireQuestions.referenceId", "=", "questionSections.questionnaireSectionId");
                    })
                    ->select("clientFillUpQuestionnaireQuestions.*")
                    ->where("questionnaireQuestions.questionnaireTempleteId", $questionnaireTemplateId)
                    ->where("questionnaireQuestions.entityType", "questionnaireSection")
                    ->where("clientFillUpQuestionnaireQuestions.isActive", 1)
                    ->get();
                $totalFillupAns = count($fillUpQuestion);
                if ($totalQuestion > $totalFillupAns) {
                    $status = "Inprogress";
                    $percentageStatus = ($totalFillupAns * 100) / $totalQuestion;
                } else {
                    $status = "Complete";
                    $percentageStatus = ($totalFillupAns * 100) / $totalQuestion;
                }

                ClientQuestionnaireTemplate::where("clientFillUpQuestionnaireId", $clientFillUpQuestionnaireId)
                    ->update([
                        "status" => $status,
                        "percentage" => $percentageStatus
                    ]);
            }
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function addQuestionnaireTemplateByUsersOld1($request, $id)
    {
        try {
            $post = $request->all();
            unset($post["provider"], $post["location"]);
            $clientQuestionnaire = ClientQuestionnaireTemplate::where('udid', $id)->first();
            if (isset($clientQuestionnaire->clientFillUpQuestionnaireId)) {
                $clientFillUpQuestionnaireId = $clientQuestionnaire->clientFillUpQuestionnaireId;
                $this->deleteQuestionnaireFillUpAnswer($clientFillUpQuestionnaireId);
            } else {
                $template = QuestionnaireTemplate::where("udid", $id)->first();
                if (!empty($template)) {
                    $role = Auth::user()->roleId;
                    $userIdd = Auth::user()->id;
                    if ($role == 1 || $role == 3) {
                        $entitypeId = "246";
                        $staff = Staff::where("userId", $userIdd)->first();
                        $loginUserId = $staff->id;
                    } elseif ($role == 4) {
                        $entitypeId = "247";
                        $patient = Patient::where("userId", $userIdd)->first();
                        $loginUserId = $patient->id;
                    } else {
                        die("entityType Required!!!");
                    }
                    $obj = [
                        'udid' => Str::uuid()->toString(),
                        'questionnaireTemplateId' => $template->questionnaireTemplateId,
                        'referenceid' => $loginUserId,
                        'createdBy' => Auth::id(),
                        'entityType' => $entitypeId
                    ];
                    $lastid = ClientQuestionnaireTemplate::insertGetId($obj);
                    $clientFillUpQuestionnaireId = $lastid;
                }
            }
            if (isset($clientFillUpQuestionnaireId) && isset($post)) {
                foreach ($post as $postArr) {
                    $question = Question::where('udid', $postArr["question"])->first();
                    $questionnaireResponse = [
                        'udid' => Str::uuid()->toString(), 'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId,
                        'questionnaireQuestionId' => $question->questionId
                    ];
                    if (isset($postArr["sectionId"])) {
                        $questionnaireSection = QuestionnaireSection::where('udid', $postArr["sectionId"])->first();
                        if (isset($questionnaireSection->questionnaireSectionId)) {
                            $questionnaireResponse["referenceId"] = $questionnaireSection->questionnaireSectionId;
                            $questionnaireResponse["entityType"] = "questionnaireSection";
                        }
                    }
                    $responseId = ClientQuestionResponse::insertGetId($questionnaireResponse);
                    // $responseId = DB::select("CALL addClientQuestionnaireResponse('" . JSON_ENCODE($questionnaireResponse)  . "')");
                    if ($postArr["dataType"] == 243) {
                        $answer = $postArr["answer"];
                        if (!empty($answer)) {
                            $this->insertQuestionOption($postArr, $answer, $responseId, $clientFillUpQuestionnaireId);
                        }
                    } elseif ($postArr["dataType"] == 241 || $postArr["dataType"] == 242) {
                        if (!empty($postArr["answer"])) {
                            $question = Question::where('udid', $postArr["question"])->first();
                            $getScore = QuestionScore::where('referenceId', $question->questionId)
                                ->where("entityType", "253")
                                ->first();
                            // echo $postArr["question"];
                            $questionScore = 0;
                            if (isset($getScore->score)) {
                                $questionScore = $getScore->score;
                            }
                            $response = [
                                'udid' => Str::uuid()->toString(), 'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId, 'clientFillupQuestionnaireQuestionId' => $responseId,
                                'dataType' => $postArr["dataType"], 'response' => $postArr["answer"]
                            ];

                            $responseAnswer = ClientResponseAnswer::insertGetId($response);
                            // $responseAnswer=DB::select("CALL addClientResponseAnswer('" . JSON_ENCODE($response)  . "')");
                            $programScore = ['udid' => Str::uuid()->toString(), 'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId, 'dataType' => $postArr["dataType"], 'score' => $questionScore, 'referenceId' => $responseAnswer, 'entityType' => 256];
                            DB::select("CALL addClientQuestionScore('" . JSON_ENCODE($programScore) . "')");
                        }
                    } elseif ($postArr["dataType"] == 244) {
                        $answer = $postArr["answer"];
                        if (!empty($answer)) {
                            foreach ($answer as $value) {
                                if (!empty($value)) {
                                    $this->insertQuestionOption($postArr, $value, $responseId, $clientFillUpQuestionnaireId);
                                }
                            }
                        }
                    }
                }
                return response()->json(['message' => trans('messages.createdSuccesfully')]);
            }
            return response()->json(['message' => "template id invalid."], 400);
        } catch (\Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function deleteQuestionnaireFillUpAnswer($clientFillUpQuestionnaireId)
    {
        try {
            if (isset($clientFillUpQuestionnaireId)) {
                //Delete client Question Response.
                $deleteDataArr = ['deletedBy' => Auth::id(), 'isActive' => 0, 'isDelete' => 1];
                ClientQuestionResponse::where("clientFillUpQuestionnaireId", $clientFillUpQuestionnaireId)->update($deleteDataArr);
                // Delete client response answer
                $clientResponseAnswerData = ['deletedBy' => Auth::id(), 'isActive' => 0, 'isDelete' => 1];
                ClientResponseAnswer::where("clientFillUpQuestionnaireId", $clientFillUpQuestionnaireId)->update($deleteDataArr);
                // delete client response program
                ClientResponseProgram::where("clientFillUpQuestionnaireId", $clientFillUpQuestionnaireId)->update($deleteDataArr);
                // delete client response program
                DB::table("clientQuestionScore")->where("clientFillUpQuestionnaireId", $clientFillUpQuestionnaireId)->update($deleteDataArr);
            }
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }


    public function insertQuestionOptionNew($postArr, $answer, $responseId, $clientQuestionnaireAssignId)
    {
        try {
            $questionOption = QuestionOption::where('udid', $answer)->first();
            $questionOptionProgram = QuestionOptionProgram::where('questionOptionId', $questionOption->questionOptionId)->get();
            // insert Option
            $response = [
                'udid' => Str::uuid()->toString(),
                'clientQuestionnaireAssignId' => $clientQuestionnaireAssignId,
                'clientFillupQuestionnaireQuestionId' => $responseId,
                'dataType' => $postArr["dataType"], 'response' => $answer
            ];
            $responseAnswerLastId = ClientResponseAnswer::insertGetId($response);
            // DB::select("CALL addClientResponseAnswer('" . JSON_ENCODE($response)  . "')");
            // insert program
            foreach ($questionOptionProgram as $pro) {
                $input = [
                    'udid' => Str::uuid()->toString(),
                    'createdBy' => Auth::id(),
                    'clientQuestionnaireAssignId' => $clientQuestionnaireAssignId,
                    'clientResponseAnswerId' => $responseAnswerLastId,
                    'program' => $pro->programId
                ];
                $lstId = ClientResponseProgram::insertGetId($input);
                // Insert Program score (255 is program score) and 257 for program response
                $programScoreObjA = QuestionScore::where("referenceId", $pro->questionOptionProgramId)
                    ->where("entityType", 255)->first();
                if (isset($programScoreObjA->score)) {
                    $programScore = ['udid' => Str::uuid()->toString(), 'clientQuestionnaireAssignId' => $clientQuestionnaireAssignId, 'dataType' => $postArr["dataType"], 'score' => $programScoreObjA->score, 'referenceId' => $lstId, 'entityType' => 257];
                    DB::select("CALL addClientQuestionScore('" . JSON_ENCODE($programScore) . "')");
                }
            }
            // Insert label score (254 is option labelScore) and 256 for client response
            $scoreObj = QuestionScore::where("referenceId", $questionOption->questionOptionId)
                ->where("entityType", 254)->first();
            if (isset($scoreObj->score)) {
                $programScore = ['udid' => Str::uuid()->toString(), 'clientQuestionnaireAssignId' => $clientQuestionnaireAssignId, 'dataType' => $postArr["dataType"], 'score' => $scoreObj->score, 'referenceId' => $responseAnswerLastId, 'entityType' => 256];
                DB::select("CALL addClientQuestionScore('" . JSON_ENCODE($programScore) . "')");
            }
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function insertQuestionOption($postArr, $answer, $responseId, $clientFillUpQuestionnaireId)
    {
        try {
            $questionOption = QuestionOption::where('udid', $answer)->first();
            if (isset($postArr["sectionId"]) && isset($postArr["question"])) {
                $questionSection = QuestionnaireSection::where("udid", $postArr["sectionId"])->first();
                $question = Question::where("udid", $postArr["question"])->first();
            } else {
                $questionSection = [];
                $question = [];
            }

            $optionObjaRR = [];
            if (isset($questionSection->questionnaireSectionId) && isset($question->questionId)) {
                $optionObjaRR = QuestionChanges::where("sectionId", $questionSection->questionnaireSectionId);
                $optionObjaRR->where("questionId", $question->questionId);
                $optionObjaRR->where("entityType", "templateOption");
                $optionObjaRR = $optionObjaRR->first();
            }

            $labelScore = 0;
            $program = [];
            if (isset($optionObjaRR->dataObj)) {
                $optionData = json_decode($optionObjaRR->dataObj);
                if (!empty($optionData->option)) {
                    foreach ($optionData->option as $data) {
                        if ($data->id == $answer) {
                            if (isset($data->labelScore)) {
                                $labelScore = $data->labelScore;
                            } else {
                                $labelScore = "";
                            }
                            if (!empty($data->program)) {
                                $program = QuestionChangeService::getCustomProgram($data->program);
                            }
                        }
                    }
                }
            }

            // insert Option
            $response = [
                'udid' => Str::uuid()->toString(),
                'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId,
                'clientFillupQuestionnaireQuestionId' => $responseId,
                'dataType' => $postArr["dataType"],
                'response' => $answer
            ];
            $responseAnswerLastId = ClientResponseAnswer::insertGetId($response);

            // insert program
            if (count($program) > 0) {
                $input = [];
                foreach ($program as $pro) {
                    $input = [
                        'udid' => Str::uuid()->toString(),
                        'createdBy' => Auth::id(),
                        'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId,
                        'clientResponseAnswerId' => $responseAnswerLastId,
                        'program' => $pro["programId"]
                    ];

                    $lstId = ClientResponseProgram::insertGetId($input);

                    // Insert Program score (255 is program score) and 257 for program response
                    if ($pro["score"] > 0 && $lstId > 0) {
                        $programScore = ['udid' => Str::uuid()->toString(), 'createdBy' => Auth::id(), 'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId, 'dataType' => $postArr["dataType"], 'score' => $pro["score"], 'referenceId' => $lstId, 'entityType' => 257];
                        ClientQuestionScore::insertGetId($programScore);
                        // DB::select("CALL addClientQuestionScore('" . JSON_ENCODE($programScore)  . "')");
                    }
                }
            }
            // Insert label score (254 is option labelScore) and 256 for client response
            QuestionScore::where("referenceId", $questionOption->questionOptionId)
                ->where("entityType", 254)->first();
            if ($labelScore > 0) {
                $programScore = ['udid' => Str::uuid()->toString(), 'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId, 'dataType' => $postArr["dataType"], 'score' => $labelScore, 'referenceId' => $responseAnswerLastId, 'entityType' => 256];
                DB::select("CALL addClientQuestionScore('" . JSON_ENCODE($programScore) . "')");
            }
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function insertQuestionOption1($postArr, $answer, $responseId, $clientFillUpQuestionnaireId)
    {
        try {
            $questionOption = QuestionOption::where('udid', $answer)->first();
            $questionOptionProgram = QuestionOptionProgram::where('questionOptionId', $questionOption->questionOptionId)->get();
            // insert Option
            $response = [
                'udid' => Str::uuid()->toString(),
                'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId,
                'clientFillupQuestionnaireQuestionId' => $responseId,
                'dataType' => $postArr["dataType"],
                'response' => $answer
            ];
            $responseAnswerLastId = ClientResponseAnswer::insertGetId($response);
            // DB::select("CALL addClientResponseAnswer('" . JSON_ENCODE($response)  . "')");
            // insert program
            foreach ($questionOptionProgram as $pro) {
                $input = [
                    'udid' => Str::uuid()->toString(),
                    'createdBy' => Auth::id(),
                    'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId,
                    'clientResponseAnswerId' => $responseAnswerLastId,
                    'program' => $pro->programId
                ];
                $lstId = ClientResponseProgram::insertGetId($input);
                // Insert Program score (255 is program score) and 257 for program response
                $programScoreObjA = QuestionScore::where("referenceId", $pro->questionOptionProgramId)
                    ->where("entityType", 255)->first();
                if (isset($programScoreObjA->score)) {
                    $programScore = ['udid' => Str::uuid()->toString(), 'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId, 'dataType' => $postArr["dataType"], 'score' => $programScoreObjA->score, 'referenceId' => $lstId, 'entityType' => 257];
                    DB::select("CALL addClientQuestionScore('" . JSON_ENCODE($programScore) . "')");
                }
            }
            // Insert label score (254 is option labelScore) and 256 for client response
            $scoreObj = QuestionScore::where("referenceId", $questionOption->questionOptionId)
                ->where("entityType", 254)->first();
            if (isset($scoreObj->score)) {
                $programScore = ['udid' => Str::uuid()->toString(), 'clientFillUpQuestionnaireId' => $clientFillUpQuestionnaireId, 'dataType' => $postArr["dataType"], 'score' => $scoreObj->score, 'referenceId' => $responseAnswerLastId, 'entityType' => 256];
                DB::select("CALL addClientQuestionScore('" . JSON_ENCODE($programScore) . "')");
            }
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function paginate($items, $perPage = 20, $page = null, $options = [])
    {
        try {
            $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);
            $items = $items instanceof Collection ? $items : Collection::make($items);
            return new LengthAwarePaginator($items->forPage($page, $perPage), $items->count(), $perPage, $page, $options);
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function getNextQuestion($request, $id)
    {
        try {
            if ($id) {
                $questionOption = QuestionOption::where("udid", $id)->first();
                if (isset($questionOption->questionOptionId)) {
                    $questions = Question::where("referenceId", $questionOption->questionOptionId)
                        ->where("entityType", "questionOptions")
                        ->get();
                    $k = 0;
                    $objQuestion = [];
                    foreach ($questions as $question) {
                        $objQuestion[$k]["questionId"] = $question->questionId;
                        $objQuestion[$k]["id"] = $question->udid;
                        $objQuestion[$k]["question"] = $question->question;
                        $objQuestion[$k]["dataTypeId"] = $question->dataTypeId;
                        $objQuestion[$k]["dataType"] = $question->questionsDataType->name;
                        $objQuestion[$k]["options"] = fractal()->collection($question->questionOption)->transformWith(new QuestionOptionTransformer())->serializeWith(new \Spatie\Fractalistic\ArraySerializer())->toArray();
                        $k++;
                    }
                    return ["data" => $objQuestion];
                    // return fractal()->collection($question)->transformWith(new QuestionTransformer())->serializeWith(new \Spatie\Fractalistic\ArraySerializer())->toArray();
                }
                return response()->json(['message' => "Option id invalid."], 400);
            }
            return response()->json(['message' => "Option id invalid."], 400);
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

    public function getQuestionnaire($request, $id)
    {
        try {
            $questionniare = ClientQuestionnaireAssign::with("questionnaireTemplate");
            $entityType = "";
            // for staff
            $user = Staff::where("udid", $id)->first();
            if (isset($user->id)) {
                $entityType = "246";
                $assignToUser = $user->id;
            } else {
                $assignToUser = "";
            }

            if (empty($assignToUser)) {
                // for admin
                $user = Patient::where("udid", $id)->first();
                if (isset($user->id)) {
                    $entityType = "247";
                    $assignToUser = $user->id;
                } else {
                    $assignToUser = "";
                }
            }

            $questionniare->where("entityType", $entityType);

            if ($assignToUser) {
                $questionniare->where("referenceId", $assignToUser);
            } else {
                return response()->json(['message' => "user id invalid."], 404);
            }
            $questionniare->get();
        } catch (Exception $e) {
            throw new \RuntimeException($e);
        }
    }

}
