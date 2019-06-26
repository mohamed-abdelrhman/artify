<?php

namespace Artify\Artify\Artifies;

use Artify\Artify\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Console\DetectsApplicationNamespace;
use Illuminate\Filesystem\Filesystem;

class RegisterAuthorizationCommand extends Command
{
    use DetectsApplicationNamespace;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'artify:register-authorization';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setting Artify Authorization Policy & Gates.';
    private $filesystem;
    protected $permissions = [];
    protected $domains = [];
    protected $currentDomain = null;
    protected $views = [
        'artifies/stubs/AuthyServiceProvider.stub' => 'Providers/AuthyServiceProvider.php',
    ];
    protected $crudPermissions = ['create','view','update','delete'];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(Filesystem $filesystem)
    {
        parent::__construct();
        $this->filesystem = $filesystem;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Gathering Information of Roles');
        $permissions = app(Role::class)->pluck(config('artify.permissions_column'))->collapse();
        if (!count($permissions)) {
            return $this->error('Roles are not set yet, or maybe they are not an array.');
        }

        if (config('artify.is_adr')) {
            $domains = collect($this->filesystem->directories(app_path()))->map(function ($value) {
                $domain = array_last(explode('/', $value));
                if (in_array($domain, ['App','Console','Exceptions', 'Http','Providers','Responses','Policies'])) {
                    return;
                }
                return $domain;
            })->reject(function ($name) {
                return empty($name);
            })->mapToDictionary(function ($domain) use ($permissions) {
                return [
                    $domain => $permissions->filter(function ($value, $permission) use ($domain) {
                        return str_contains($permission, strtolower(str_singular($domain)));
                    })
                ];
            });
            foreach ($domains as $domain => $domainPermissions) {
                $this->preparePermissions($domainPermissions[0]);
                $this->currentDomain = $domain;
                $this->qualifyClass();
            }
        } else {
            $this->preparePermissions($permissions);
            $this->qualifyClass();
        }
    }

    protected function preparePermissions($permissions)
    {
        $this->permissions = $permissions->mapToDictionary(function ($value, $key) {
            $key = explode('-', $key);
            return [ str_plural($key[1]) => $key[0]];
        });
    }
    protected function qualifyClass()
    {
        $this->info('Registering Policies & Gates !');
        foreach ($this->views as $key => $view) {
            $this->setContent(
                $this->filesystem->get(artify_path($key))
            )->buildClass()->transferContent(artify_path($key), app_path($view), $this->getContent());
        }
        $content = $this->filesystem->get(config_path('app.php'));
        if (!strpos($content, 'App\Providers\AuthyServiceProvider::class')) {
            $this->info('Registering Authy Service Provider ');
            $content = str_replace('App\Providers\AuthServiceProvider::class,', "App\Providers\AuthServiceProvider::class,\n\t\t\t\tApp\Providers\AuthyServiceProvider::class,", $content);
            $this->filesystem->put(config_path('app.php'), $content);
        } else {
            $this->info('It Seems that Authy Service Provider has been registered earlier.');
        }
    }
    protected function setContent($content)
    {
        $this->content = $content;
        return $this;
    }
    protected function getContent()
    {
        return $this->content;
    }
    protected function buildClass()
    {
        $this->appendPolicies()->appendGates();
        foreach ($this->permissions as $permission => $value) {
            $model = str_singular(ucfirst($permission));
            $this->replacePolicies($model)->replaceGates($model, $value);
            $this->generatePolicyStub($model, $value);
        }
        return $this->setContent(
            str_replace(['use App\DummyModel;', 'use App\Policies\DummyPolicy;'], '', $this->getContent())
        );
    }
    protected function appendPolicies()
    {
        return $this->setContent(
            str_replace(
                'App\DummyModel::class => App\Policies\DummyPolicy::class',
                str_repeat(
                    "App\DummyModel::class => App\Policies\DummyPolicy::class,\n\t\t",
                    $this->permissions->count()
                ),
                $this->getContent()
            )
        );
    }

    protected function appendGates()
    {
        return $this->setContent(
            str_replace(
                'Gate::define(\'dummy-access\',\'\App\Policies\DummyPolicy@DummyAction\');',
                str_repeat(
                    "Gate::define(\'dummy-access\',\'\App\Policies\DummyPolicy@DummyAction\');\n\t\t",
                    config('artify.is_adr') ? count($this->permissions[strtolower($this->currentDomain)]) : $this->permissions->collapse()->count()
                ),
                $this->getContent()
            )
        );
    }
    protected function getDomainDirectory()
    {
        return optional(!$this->currentDomain, function ($domain) {
            if (config('artify.is_adr')) {
                return app_path($this->currentDomain);
            }
            return app_path();
        });
    }
    protected function getDomainNamespace()
    {
        return optional(!$this->currentDomain, function ($domain) {
            if (config('artify.is_adr')) {
                return 'App\\' . $this->currentDomain . '\\';
            }
            return config('artify.models.namespace');
        });
    }
    protected function getDomainModelNamespace()
    {
        return optional(!$this->currentDomain, function ($domain) {
            if (config('artify.is_adr')) {
                return $this->getDomainNamespace() . 'Domain\\Models\\';
            }
            return config('artify.models.namespace');
        });
    }
    protected function getDomainPolicyNamespace()
    {
        return optional(!$this->currentDomain, function ($domain) {
            if (config('artify.is_adr')) {
                return $this->getDomainNamespace() . 'Domain\\Policies\\';
            }
            return "App\Policies\\";
        });
    }
    protected function replaceModelNamespace($model)
    {
        return $this->setContent(
            str_replace_first(
                'App\DummyModel',
                $this->getDomainModelNamespace() . ucfirst($model),
                $this->getContent()
            )
        );
    }

    protected function replacePolicies($model)
    {
        return $this->setContent(
            str_replace_first(
                "App\\DummyModel::class => App\\Policies\\DummyPolicy::class",
                $this->getDomainModelNamespace() . "{$model}::class => " . $this->getDomainPolicyNamespace() . "{$model}Policy::class",
                $this->getContent()
            )
        );
    }
    protected function replaceGates($model, $permissions)
    {
        foreach ($permissions as $permission) {
            $access = $permission.'-'.lcfirst($model);
            $this->setContent(str_replace_first("Gate::define(\'dummy-access\',\'\App\Policies\DummyPolicy@DummyAction\');\n", "Gate::define('$access',\"{$this->getDomainPolicyNamespace()}{$model}Policy@$permission\");\n", $this->getContent()));
        }
        return $this;
    }

    protected function generatePolicyStub($model, $permissions)
    {
        $originalContent = $this->filesystem->get(artify_path('artifies/stubs/Policy.stub'));
        if (str_contains($model, 'User')) {
            $content = str_replace(['use NamespacedDummyModel;',', DummyModel $dummyModel'], '', $originalContent);
        } else {
            $content = str_replace('use NamespacedDummyModel;', 'use ' .$this->getDomainModelNamespace() .ucfirst($model).';', $originalContent);
        }
        if (in_array('approve', $permissions)) {
            $content = str_replace('use HandlesAuthorization;', "use HandlesAuthorization;\n\tpublic function approve(User \$user,".ucfirst($model).' $'.lcfirst($model).")\n\t{\n\t\treturn \$user->hasRole('approve-".lcfirst($model)."') || \$user->id == \$".lcfirst($model)."->user_id;\n\t}", $content);
        }
        $content = str_replace([
            'DummyClass',
            'DummyModel',
            'dummyModel',
            '-dummy',
            '$dummy',
            'App\\Policies'
        ], [
            "{$model}Policy",
            $model,
            lcfirst($model),
            '-'.lcfirst($model),
            '$'.lcfirst($model),
            rtrim($this->getDomainPolicyNamespace(), '\\')
        ], $content);
        $this->hasOrCreateDirectory($this->getDomainPolicyDirectory())
            ->transferContent(
                artify_path('artifies/stubs/Policy.stub'),
                $this->getDomainPolicyDirectory() . "{$model}Policy.php",
                $content
            );
    }
    protected function getDomainPolicyDirectory()
    {
        return optional(!$this->currentDomain, function ($domain) {
            if (config('artify.is_adr')) {
                return $this->getDomainDirectory() . '/Domain/Policies/';
            }
            return app_path('Policies/');
        });
    }
    protected function transferContent($source, $destination, $content)
    {
        $originalContent = $this->filesystem->get($source);
        $this->filesystem->put($source, $content);
        copy($source, $destination);
        $this->filesystem->put($source, $originalContent);
        return $this;
    }
    protected function hasOrCreateDirectory($directory)
    {
        if (!$this->hasDirectory($directory)) {
            $this->filesystem->makeDirectory($directory, 0755, true, true);
        }
        return $this;
    }
    protected function hasDirectory($directory)
    {
        return $this->filesystem->exists($directory);
    }
}
