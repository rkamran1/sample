<?php

namespace App;

use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Nicolaslopezj\Searchable\SearchableTrait as SearchableTrait;
use Zizaco\Entrust\Entrust;
use Zizaco\Entrust\Traits\EntrustUserTrait;
use Illuminate\Database\Eloquent\Builder;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use Notifiable, EntrustUserTrait;
    use SearchableTrait;


    protected $searchable = [
        'columns' => [
            'users.name' => 10,
        ],
        'join' => [
            'client_details' => ['client_details.user_id','users.id'],
        ],
    ];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('active', function (Builder $builder) {
            $builder->where('status', '=', 'active');
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password','is_organisation_verified','verify_token'

    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    public $dates = ['created_at', 'updated_at'];

    /**
     * Route notifications for the Slack channel.
     *
     * @return string
     */
    public function routeNotificationForSlack()
    {
        $slack = SlackSetting::first();
        return $slack->slack_webhook;
    }


    public function client()
    {
        return $this->hasMany(ClientDetails::class, 'user_id');
    }

    public function employee()
    {
        return $this->hasMany(EmployeeDetails::class, 'user_id');
    }

    public function projects()
    {
        return $this->hasMany(Project::class, 'client_id');
    }

    public function member() {
        return $this->hasMany(ProjectMember::class, 'user_id');
    }

    public function role() {
        return $this->hasMany(RoleUser::class, 'user_id');
    }

    public function attendee() {
        return $this->hasMany(EventAttendee::class, 'user_id');
    }

    public function agent(){
        return $this->hasMany(TicketAgentGroups::class, 'agent_id');
    }

    public function group(){
        return $this->hasMany(EmployeeTeam::class, 'user_id');
    }

    public function employee_resumes(){
        return $this->hasMany(EmployeeResume::class, 'user_id');
    }


    public static function allClients()
    {
        return User::join('role_user', 'role_user.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->select('users.id', 'users.name', 'users.email', 'users.created_at')
            ->where('roles.name', 'client')
            ->get();
    }

    public static function allOrganisationClients($organisationId){
        return User::join('role_user', 'role_user.user_id', '=', 'users.id')
            ->leftJoin('client_details', 'client_details.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')

            ->select('users.id', 'users.name', 'users.email', 'users.created_at')

            ->where('roles.name', 'client')
            ->where('client_details.organisation_setting_id', '=', $organisationId)
            ->get();
    }

    public static function allEmployees($exceptId = NULL)
    {
        $users = User::join('role_user', 'role_user.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->select('users.id', 'users.name', 'users.email', 'users.created_at')
            ->where('roles.name', '<>', 'client');

        if(!is_null($exceptId)){
            $users->where('users.id', '<>', $exceptId);
        }

        $users->groupBy('users.id');
        return $users->get();
    }

    public static function allOrganisationUser($organisationId, $exceptId = NULL)
    {
        $users = User::join('role_user', 'role_user.user_id', '=', 'users.id')
            ->join('organisation_user_maps', 'organisation_user_maps.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->select('users.id', 'users.name', 'users.email', 'users.created_at')
            ->where('roles.name', '<>', 'client')
            ->where('organisation_user_maps.organisation_setting_id', $organisationId);

        if(!is_null($exceptId)){
            $users->where('users.id', '<>', $exceptId);
        }

        $users->groupBy('users.id');
        return $users->get();
    }

    public static function allAdmins($exceptId = NULL)
    {
        $users = User::join('role_user', 'role_user.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->select('users.id', 'users.name', 'users.email', 'users.created_at')
            ->where('roles.name', 'admin');

        if(!is_null($exceptId)){
            $users->where('users.id', '<>', $exceptId);
        }

        return $users->get();
    }

    public static function teamUsers($teamId)
    {
        $users = User::join('employee_teams', 'employee_teams.user_id', '=', 'users.id')
            ->select('users.id', 'users.name', 'users.email', 'users.created_at')
            ->where('employee_teams.team_id', $teamId);

        return $users->get();
    }

    public static function isAdmin($userId){
        $role = Role::where('name', 'admin')->first();
        $user = RoleUser::where('role_id', $role->id)
                ->where('user_id', $userId)
                ->first();

        if(!is_null($user)){
            return true;
        }
        return false;
    }

    public static function isClient($userId){
        $role = Role::where('name', 'client')->first();
        $user = RoleUser::where('role_id', $role->id)
                ->where('user_id', $userId)
                ->first();

        if(!is_null($user)){
            return true;
        }
        return false;
    }

    public static function isEmployee($userId){
        $role = Role::where('name', 'employee')->first();
        $user = RoleUser::where('role_id', $role->id)
                ->where('user_id', $userId)
                ->first();

        if(!is_null($user)){
            return true;
        }
        return false;
    }

    public static function loginWithSocialNetwork($socialUser, $provider){
        $userSocialNetwork  = UserSocialNetwork::getSocialUser($socialUser->id, $provider);
        if (!$userSocialNetwork) {
            $user = User::where('email', '=', $socialUser->email)->first();
            if (!$user) {
                $user = new User();
                $user->name = $socialUser->name;
                $user->email = $socialUser->email;
                $user->password = $provider . '_account';
                if($provider == 'google' && isset($socialUser->user['gender'])){
                    $user->gender = $socialUser->user['gender'];
                }
                $user->save();
            }

            $userSocialNetwork = new UserSocialNetwork();
            $userSocialNetwork->user_id = $user->id;
            $userSocialNetwork->name = $socialUser->name;
            $userSocialNetwork->provider = $provider;
            $userSocialNetwork->access_token = $socialUser->token;
            $userSocialNetwork->social_uid = $socialUser->id;
            $userSocialNetwork->save();
        }

        $user = $userSocialNetwork->user;

        if($socialUser->user && isset($socialUser->user['headline'])){
            // check if user has employee details
            $employeeDetails = $user->employee()->first();
            if(!$employeeDetails){
                $employeeDetails = new EmployeeDetails();
                $employeeDetails->user_id = $user->id;
                $employeeDetails->job_title = $socialUser->user['headline'];
                $employeeDetails->address = 'address';
                if($socialUser->user['location'] && $socialUser->user['location']['name']){
                    $employeeDetails->address = $socialUser->user['location']['name'];
                }
                $employeeDetails->save();
            }
        }

        if ($socialUser->avatar && is_null($user->image)) {
                $user->image = strtotime(date('Y-m-d H:i:s')) . '.jpg';
                // resize the image to a width of 300 and constrain aspect ratio (auto height)
                $img = Image::make($socialUser->avatar_original);
                $img->resize(300, null, function ($constraint) {
                    $constraint->aspectRatio();
                });
                $img->save('user-uploads/avatar/'.$user->image);
                $user->save();
        }

        $user = $userSocialNetwork->user;

        return $user;
    }

    public function findOrCreateEmployeeDetails(){
        return EmployeeDetails::firstOrCreate(['user_id' => $this->id], ['job_title' => ""]);
    }

    public function organisationUserMap(){
        return $this->hasOne(OrganisationUserMap::class, 'user_id');
    }

    public static function generateVerifyToken(){
        do {
            $verifyToken = Str::random(40);
            $userWithToken = self::where('verify_token', '=', $verifyToken)->first();
        } while($userWithToken);
        return $verifyToken;
    }



}