<?php
/** Sample code for controller */
namespace App\Http\Controllers\Admin;

use App\EmployeeDetails;
use App\Helper\Reply;
use App\Http\Requests\User\StoreUser;
use App\Http\Requests\User\UpdateEmployee;
use App\Leave;
use App\LeaveType;
use App\ModuleSetting;
use App\Notifications\NewUser;
use App\OrganisationUserMap;
use App\Project;
use App\ProjectTimeLog;
use App\Role;
use App\RoleUser;
use App\Task;
use App\User;
use App\UserActivity;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Facades\Image;
use Maatwebsite\Excel\Facades\Excel;
use Yajra\Datatables\Facades\Datatables;

class ManageEmployeesController extends AdminBaseController
{

    public function __construct() {
        parent::__construct();
        $this->pageTitle = __('app.menu.employees');
        $this->pageIcon = 'icon-user';

        $this->middleware(function($request, $next){
            if(!ModuleSetting::checkModule('employees', $this->global->id)){
                abort(403);
            }
            return $next($request);
        });
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        $this->totalEmployees = count(User::allOrganisationUser($this->global->id));
        return view('admin.employees.index', $this->data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request) {
        $this->source = $request->get('source', '');
        $employee = new EmployeeDetails();
        $this->fields = $employee->getCustomFieldGroupsWithFields()->fields;
        $view = view('admin.employees.create', $this->data)->render();
        return Reply::dataOnly(['status' => 'success', 'view' => $view]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreUser $request) {
        $validate = Validator::make(['job_title' => $request->job_title, 'hourly_rate' => $request->hourly_rate, 'joining_date' => $request->joining_date], [
            'job_title' => 'required',
            'hourly_rate' => 'numeric',
            'joining_date' => 'required'
        ]);

        if ($validate->fails()) {
            return Reply::formErrors($validate);
        }

        $user = new User();
        $user->name = $request->input('name');
        $user->email = $request->input('email');
        $user->password = Hash::make($request->input('password'));
        $user->mobile = $request->input('mobile');
        $user->gender = $request->input('gender');

        if ($request->hasFile('image')) {
            File::delete('user-uploads/avatar/'.$user->image);

            $user->image = $request->image->hashName();
            $request->image->store('user-uploads/avatar');

            // resize the image to a width of 300 and constrain aspect ratio (auto height)
            $img = Image::make('user-uploads/avatar/'.$user->image);
            $img->resize(300, null, function ($constraint) {
                $constraint->aspectRatio();
            });
            $img->save();
        }

        $user->save();

        if ($user->id) {
            //map user with current organisation
            $organisationUserMap = new OrganisationUserMap();
            $organisationUserMap->user_id = $user->id;
            $organisationUserMap->organisation_setting_id = $this->global->id;
            $organisationUserMap->save();

            $employee = new EmployeeDetails();
            $employee->user_id = $user->id;
            $employee->job_title = $request->job_title;
            $employee->address = $request->address;
            $employee->hourly_rate = $request->hourly_rate;
            $employee->slack_username = $request->slack_username;
            $employee->joining_date = Carbon::parse($request->joining_date)->format('Y-m-d');;
            $employee->save();
        }


        // To add custom fields data
        if ($request->get('custom_fields_data')) {
            $employee->updateCustomFieldData($request->get('custom_fields_data'));
        }


        $user->attachRole(2);

        // Notify User
        $user->notify(new NewUser($request->input('password')));

        $this->logSearchEntry($user->id, $user->name, 'admin.employees.show');

        return Reply::success(__('messages.employeeAdded'));
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id) {

        $this->employee = User::withoutGlobalScope('active')->findOrFail($id);
        if(!$this->employee->organisationUserMap || ($this->employee->organisationUserMap->organisation_setting_id != $this->global->id)){
            abort(403);
        }
        $this->employeeDetail = $this->employee->findOrCreateEmployeeDetails();
        $this->taskCompleted = Task::where('user_id', $id)->where('status', 'completed')->count();
        $hoursLogged = ProjectTimeLog::where('user_id', $id)->sum('total_minutes');

        $timeLog = intdiv($hoursLogged, 60).' hrs ';

        if(($hoursLogged % 60) > 0){
            $timeLog.= ($hoursLogged % 60).' mins';
        }

        $this->hoursLogged = $timeLog;

        $this->activities = UserActivity::where('user_id', $id)->orderBy('id', 'desc')->get();
        $this->projects = Project::select('projects.id', 'projects.project_name', 'projects.deadline', 'projects.completion_percent')
            ->join('project_members', 'project_members.project_id', '=', 'projects.id')
            ->where('project_members.user_id', '=', $id)
            ->get();
        $this->leaves = Leave::byUser($id);
        $this->leaveTypes = LeaveType::byUser($id);
        $this->allowedLeaves = LeaveType::sum('no_of_leaves');

        return view('admin.employees.show', $this->data);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id) {
        $this->userDetail = User::withoutGlobalScope('active')->findOrFail($id);
        if(!$this->userDetail->organisationUserMap || ($this->userDetail->organisationUserMap->organisation_setting_id != $this->global->id)){
            abort(403);
        }
        $this->employeeDetail = $this->userDetail->findOrCreateEmployeeDetails();
        if(!is_null($this->employeeDetail)){
            $this->employeeDetail = $this->employeeDetail->withCustomFields();
            $this->fields = $this->employeeDetail->getCustomFieldGroupsWithFields()->fields;
        }

        return view('admin.employees.edit', $this->data);

    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateEmployee $request, $id) {
        $user = User::withoutGlobalScope('active')->findOrFail($id);
        $user->name = $request->input('name');
        $user->email = $request->input('email');
        if ($request->password != '') {
            $user->password = Hash::make($request->input('password'));
        }
        $user->mobile = $request->input('mobile');
        $user->gender = $request->input('gender');
        $user->status = $request->input('status');

        if ($request->hasFile('image')) {
            File::delete('user-uploads/avatar/'.$user->image);

            $user->image = $request->image->hashName();
            $request->image->store('user-uploads/avatar');

            // resize the image to a width of 300 and constrain aspect ratio (auto height)
            $img = Image::make('user-uploads/avatar/'.$user->image);
            $img->resize(300, null, function ($constraint) {
                $constraint->aspectRatio();
            });
            $img->save();
        }

        $user->save();

        $validate = Validator::make(['job_title' => $request->job_title], [
            'job_title' => 'required'
        ]);

        if ($validate->fails()) {
            return Reply::formErrors($validate);
        }

        $employee = EmployeeDetails::where('user_id', '=', $user->id)->first();
        if (empty($employee)) {
            $employee = new EmployeeDetails();
            $employee->user_id = $user->id;
        }
        $employee->job_title = $request->job_title;
        $employee->address = $request->address;
        $employee->hourly_rate = $request->hourly_rate;
        $employee->slack_username = $request->slack_username;
        $employee->joining_date = Carbon::parse($request->joining_date)->format('Y-m-d');;
        $employee->save();

        // To add custom fields data
        if ($request->get('custom_fields_data')) {
            $employee->updateCustomFieldData($request->get('custom_fields_data'));
        }

        return Reply::redirect(route('admin.employees.index'), __('messages.employeeUpdated'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id) {
        $user = User::withoutGlobalScope('active')->findOrFail($id);

        if ($user->id == 1) {
            return Reply::error(__('messages.adminCannotDelete'));
        }

        User::destroy($id);
        return Reply::success(__('messages.employeeDeleted'));
    }

    public function data() {
        $users = User::with('role')
            ->withoutGlobalScope('active')
            ->join('role_user', 'role_user.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('organisation_user_maps', 'organisation_user_maps.user_id', '=', 'users.id')
            ->select('users.id', 'users.name', 'users.email', 'users.created_at', 'roles.name as roleName', 'roles.id as roleId', 'users.image', 'users.status')
            ->where('roles.name', '<>', 'client')
            ->where('organisation_user_maps.organisation_setting_id', '=', $this->global->id)
            ->groupBy('users.id')
            ->get();

        $roles = Role::where('name', '<>', 'client')->get();

        return Datatables::of($users)
            ->addColumn('role', function ($row) use ($roles) {
                $roleRow = '';
                if ($row->id != $this->global->user_id) {

                    $flag = 0;
                    foreach($roles as $role){

                        $roleRow .= '<div class="radio radio-info">
                              <input type="radio" name="role_' . $row->id . '" class="assign_role" data-user-id="' . $row->id . '"';

                        foreach($row->role as $urole){

                            if($role->id == $urole->role_id && $flag == 0){
                                $roleRow .= ' checked ';

                                if($role->name == 'admin'){
                                    $flag = 1; //do not check any other role for user if is admin
                                }
                            }

                        }

                        if($role->id <= 3){
                            $roleRow.= 'id="none_role_' . $row->id .$role->id. '" data-role-id="'.$role->id.'" value="'.$role->id.'"> <label for="none_role_' . $row->id .$role->id . '" data-role-id="'.$role->id.'" data-user-id="' . $row->id . '">'.__('app.'.$role->name).'</label></div>';
                        }
                        else{
                            $roleRow.= 'id="none_role_' . $row->id .$role->id. '" data-role-id="'.$role->id.'" value="'.$role->id.'"> <label for="none_role_' . $row->id .$role->id . '" data-role-id="'.$role->id.'" data-user-id="' . $row->id . '">'.ucwords($role->name).'</label></div>';
                        }

                        $roleRow .= '<br>';

                    }
                    return $roleRow;

                }
                else{
                    return __('messages.roleCannotChange');
                }

            })
            ->addColumn('action', function ($row) {
                return '<a href="' . route('admin.employees.edit', [$row->id]) . '" class="btn btn-info btn-circle"
                      data-toggle="tooltip" data-original-title="Edit"><i class="fa fa-pencil" aria-hidden="true"></i></a>

                      <a href="' . route('admin.employees.show', [$row->id]) . '" class="btn btn-success btn-circle"
                      data-toggle="tooltip" data-original-title="View Employee Details"><i class="fa fa-search" aria-hidden="true"></i></a>

                      <a href="javascript:;" class="btn btn-danger btn-circle sa-params"
                      data-toggle="tooltip" data-user-id="' . $row->id . '" data-original-title="Delete"><i class="fa fa-times" aria-hidden="true"></i></a>';
            })
            ->editColumn(
                'created_at',
                function ($row) {
                    return Carbon::parse($row->created_at)->formatLocalized('%B %d, %Y');
                }
            )
            ->editColumn(
                'status',
                function ($row) {
                    if($row->status == 'active'){
                        return '<label class="label label-success">'.__('app.active').'</label>';
                    }
                    else{
                        return '<label class="label label-danger">'.__('app.deactive').'</label>';
                    }
                }
            )
            ->editColumn('name', function ($row) use ($roles) {
                $image =  ($row->image) ? '<img src="'.asset('user-uploads/avatar/'.$row->image).'"
                                                            alt="user" class="img-circle" width="30"> ': '<img src="'.asset('default-profile-2.png').'"
                                                            alt="user" class="img-circle" width="30"> ';
                if ($row->hasRole('admin')) {

                    return '<a href="' . route('admin.employees.show', $row->id) . '">' . $image.' '.ucwords($row->name) . '</a><br><br> <label class="label label-danger">'.__('app.admin').'</label>';
                }
                else{
                    foreach($roles as $role){
                        foreach($row->role as $urole){

                            if($role->id == $urole->role_id && $role->id != 2){
                                return '<a href="' . route('admin.employees.show', $row->id) . '">' . $image.' '.ucwords($row->name) . '</a><br><br> <label class="label label-info">'.ucwords($role->name).'</label>';
                            }

                        }
                    }
                    return '<a href="' . route('admin.employees.show', $row->id) . '">' . $image.' '.ucwords($row->name) . '</a><br><br> <label class="label label-warning">'.__('app.employee').'</label>';
                }
                return '<a href="' . route('admin.employees.show', $row->id) . '">' . $image.' '.ucwords($row->name) . '</a>';
            })
            ->rawColumns(['name', 'action', 'role', 'status'])
            ->removeColumn('roleId')
            ->removeColumn('roleName')
            ->make(true);
    }

    public function tasks($userId, $hideCompleted) {
        $tasks = Task::leftJoin('projects', 'projects.id', '=', 'tasks.project_id')
            ->select('tasks.id', 'projects.project_name', 'tasks.heading', 'tasks.due_date', 'tasks.status', 'tasks.project_id')
            ->where('tasks.user_id', $userId);

        if ($hideCompleted == '1') {
            $tasks->where('tasks.status', '=', 'incomplete');
        }

        $tasks->get();

        return Datatables::of($tasks)
            ->editColumn('due_date', function ($row) {
                if ($row->due_date->isPast()) {
                    return '<span class="text-danger">' . $row->due_date->format('d M, y') . '</span>';
                }
                return '<span class="text-success">' . $row->due_date->format('d M, y') . '</span>';
            })
            ->editColumn('heading', function ($row) {
                return ucfirst($row->heading);
            })
            ->editColumn('status', function ($row) {
                if ($row->status == 'incomplete') {
                    return '<label class="label label-danger">'.__('app.incomplete').'</label>';
                }
                return '<label class="label label-success">'.__('app.complete').'</label>';
            })
            ->editColumn('project_name', function ($row) {
                if(!is_null($row->project_name)){
                    return '<a href="' . route('admin.projects.show', $row->project_id) . '">' . ucfirst($row->project_name) . '</a>';
                }
            })
            ->rawColumns(['status', 'project_name', 'due_date'])
            ->removeColumn('project_id')
            ->make(true);
    }

    public function timeLogs($userId) {
        $timeLogs = ProjectTimeLog::join('projects', 'projects.id', '=', 'project_time_logs.project_id')
            ->select('project_time_logs.id', 'projects.project_name', 'project_time_logs.start_time', 'project_time_logs.end_time', 'project_time_logs.total_hours', 'project_time_logs.memo', 'project_time_logs.project_id', 'project_time_logs.total_minutes')
            ->where('project_time_logs.user_id', $userId);
        $timeLogs->get();

        return Datatables::of($timeLogs)
            ->editColumn('start_time', function ($row) {
                return $row->start_time->timezone($this->global->timezone)->format('d M, Y h:i A');
            })
            ->editColumn('end_time', function ($row) {
                if (!is_null($row->end_time)) {
                    return $row->end_time->timezone($this->global->timezone)->format('d M, Y h:i A');
                }
                else {
                    return "<label class='label label-success'>Active</label>";
                }
            })
            ->editColumn('project_name', function ($row) {
                return '<a href="' . route('admin.projects.show', $row->project_id) . '">' . ucfirst($row->project_name) . '</a>';
            })
            ->editColumn('total_hours', function($row){
                $timeLog = intdiv($row->total_minutes, 60).' hrs ';

                if(($row->total_minutes % 60) > 0){
                    $timeLog.= ($row->total_minutes % 60).' mins';
                }

                return $timeLog;
            })
            ->rawColumns(['end_time', 'project_name'])
            ->removeColumn('project_id')
            ->make(true);
    }

    public function export() {
        $rows = User::leftJoin('employee_details', 'users.id', '=', 'employee_details.user_id')
            ->select(
                'users.id',
                'users.name',
                'users.email',
                'users.mobile',
                'employee_details.job_title',
                'employee_details.address',
                'employee_details.hourly_rate',
                'users.created_at'
            )
            ->get();

        // Initialize the array which will be passed into the Excel
        // generator.
        $exportArray = [];

        // Define the Excel spreadsheet headers
        $exportArray[] = ['ID', 'Name', 'Email', 'Mobile', 'Job Title', 'Address', 'Hourly Rate', 'Created at'];

        // Convert each member of the returned collection into an array,
        // and append it to the payments array.
        foreach ($rows as $row) {
            $exportArray[] = $row->toArray();
        }

        // Generate and return the spreadsheet
        Excel::create('Employees', function ($excel) use ($exportArray) {

            // Set the spreadsheet title, creator, and description
            $excel->setTitle('Employees');
            $excel->setCreator('Aioats')->setCompany($this->companyName);
            $excel->setDescription('Employees file');

            // Build the spreadsheet, passing in the payments array
            $excel->sheet('sheet1', function ($sheet) use ($exportArray) {
                $sheet->fromArray($exportArray, null, 'A1', false, false);

                $sheet->row(1, function ($row) {

                    // call row manipulation methods
                    $row->setFont(array(
                        'bold' => true
                    ));

                });

            });


        })->download('xlsx');
    }

    public function assignRole(Request $request) {
        $userId = $request->userId;
        $roleId = $request->role;
        $employeeRole = Role::where('name', 'employee')->first();
        $user = User::findOrFail($userId);

        RoleUser::where('user_id', $user->id)->delete();
        $user->roles()->attach($employeeRole->id);
        if($employeeRole->id != $roleId){
            $user->roles()->attach($roleId);
        }

        return Reply::success(__('messages.roleAssigned'));
    }

    public function assignProjectAdmin(Request $request) {
        $userId = $request->userId;
        $projectId = $request->projectId;
        $project = Project::findOrFail($projectId);
        $project->project_admin = $userId;
        $project->save();

        return Reply::success(__('messages.roleAssigned'));
    }

}
