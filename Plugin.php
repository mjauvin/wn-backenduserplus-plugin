<?php namespace StudioAzura\BackendUserPlus;

use Backend;
use Backend\Models\User as UserModel;
use Backend\Controllers\Users as UsersController;
use Event;
use Lang;
use Mail;
use System\Classes\PluginBase;

/**
 * BackendUserPlus Plugin Information File
 */
class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name'        => 'BackendUserPlus',
            'description' => 'studioazura.backenduserplus::lang.description',
            'author'      => 'StudioAzura',
            'icon'        => 'icon-leaf'
        ];
    }

    public function boot()
    {
        $this->extendBackendUserModel();
        $this->extendBackendUserController();
    }

    protected function extendBackendUserModel()
    {
        UserModel::extend(function ($model) {
            unset($model->rules['password']);
            unset($model->rules['password_confirmation']);

            // modify Backend User fields
            Event::listen('backend.form.extendFieldsBefore', function ($widget) {
                if (!$widget->model instanceof \Backend\Models\User || !$widget->getController() instanceof \Backend\Controllers\Users) {
                    return;
                }
                $widget->tabs['fields']['send_invite']['type'] = 'radio';
                $widget->tabs['fields']['send_invite']['options'] = [
                    'credentials' => 'studioazura.backenduserplus::lang.labels.send-invite.credentials',
                    'reset' => 'studioazura.backenduserplus::lang.labels.send-invite.reset',
                    null => 'studioazura.backenduserplus::lang.labels.send-invite.do-not-send',
                ];
                $widget->tabs['fields']['send_invite']['default'] = 'credentials';

                $trigger = [
                    'action' => 'hide',
                    'field' => 'send_invite',
                    'condition' => 'value[reset]',
                ];
                $widget->tabs['fields']['password']['trigger'] = $trigger;
                $widget->tabs['fields']['password_confirmation']['trigger'] = $trigger;
            });

            // send invite email or restore email
            $model->bindEvent('model.afterCreate', function () use ($model) {
               $model->restorePurgedValues();

               if ($model->send_invite !== null) {
                   $model->sendCustomInvite();
               }
            }, 10);

            // no password required when sending a reset link by email
            $model->bindEvent('model.beforeValidate', function () use ($model) {
                if ($model->send_invite !== 'reset') {
                    $model->rules['password'] = 'required|min:8';
                    $model->rules['password_confirmation'] = 'required_with:password|same:password';
                }
            });

            $model->addDynamicMethod('sendCustomInvite', function () use ($model) {
                if ($model->send_invite === 'reset') {
                    $code = $model->getResetPasswordCode();
                    $link = Backend::url('backend/auth/reset/' . $model->id . '/' . $code);
                    $password = null;
                } else {
                    $link = Backend::url('backend');
                    $password = $model->getOriginalHashValue('password');
                }

                $data = [
                    'name' => $model->full_name,
                    'login' => $model->login,
                    'password' => $password,
                    'link' => $link,
                ];

                Mail::send('studioazura.backenduserplus::mail.invite', $data, function ($message) use ($model) {
                    $message->to($model->email, $model->full_name);
                });

                $model->send_invite = null;
                $model->purgeAttributes('send_invite');
            });
        });
    }

    protected function extendBackendUserController()
    {
        UsersController::extend(function ($controller) {
            list($author, $plugin) = explode('\\', strtolower(get_class()));
            $partials_path = sprintf('$/%s/%s/partials/users', $author, $plugin);
            $controller->addViewPath($partials_path);

            $controller->addDynamicMethod('onPurgeDeleted', function () use ($controller) {
                UserModel::onlyTrashed()->forceDelete();
                return $controller->listRefresh();
            });
        });
    }
}