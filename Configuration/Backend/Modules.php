<?php

use AskoEducation\Cbm\Controller\ContentBlockModuleController;

return [
    'content_cbm' => [
        'parent' => 'content',
        'access' => 'admin',
        'path' => '/module/content/content-blocks',
        'iconIdentifier' => 'module-cbm',
        'labels' => 'cbm.module',
        'inheritNavigationComponentFromMainModule' => false,
        'routes' => [
            '_default' => [
                'target' => ContentBlockModuleController::class . '::overviewAction',
            ],
            'labels' => [
                'target' => ContentBlockModuleController::class . '::labelsAction',
            ],
            'labelsApply' => [
                'target' => ContentBlockModuleController::class . '::labelsApplyAction',
                'methods' => ['POST'],
            ],
            'labelsSave' => [
                'target' => ContentBlockModuleController::class . '::labelsSaveAction',
                'methods' => ['POST'],
            ],
            'labelsDelete' => [
                'target' => ContentBlockModuleController::class . '::labelsDeleteAction',
                'methods' => ['POST'],
            ],
            'create' => [
                'target' => ContentBlockModuleController::class . '::createAction',
            ],
            'createSubmit' => [
                'target' => ContentBlockModuleController::class . '::createSubmitAction',
                'methods' => ['POST'],
            ],
            'createFinish' => [
                'target' => ContentBlockModuleController::class . '::createFinishAction',
            ],
            'edit' => [
                'target' => ContentBlockModuleController::class . '::editAction',
            ],
            'editSubmit' => [
                'target' => ContentBlockModuleController::class . '::editSubmitAction',
                'methods' => ['POST'],
            ],
            'editFinish' => [
                'target' => ContentBlockModuleController::class . '::editFinishAction',
            ],
            'preview' => [
                'target' => ContentBlockModuleController::class . '::previewAction',
            ],
            'previewApply' => [
                'target' => ContentBlockModuleController::class . '::previewApplyAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
