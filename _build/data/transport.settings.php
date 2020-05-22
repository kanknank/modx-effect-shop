<?php

$data['modSystemSetting'] = [

    /** робокасса */

    'rid' => [
        'fields' => [
            'key' => $config['component']['namespace'].'.robokassa.id',
            'value' => '',
            'xtype' => 'textfield',
            'namespace' => $config['component']['namespace'],
            'area' => $config['component']['namespace'].'.main'
        ],
        'options' => $config['data_options']['modSystemSetting']
    ],

    'rtest' => [
        'fields' => [
            'key' => $config['component']['namespace'].'.robokassa.is_test',
            'value' => '1',
            'xtype' => 'textfield',
            'namespace' => $config['component']['namespace'],
            'area' => $config['component']['namespace'].'.main'
        ],
        'options' => $config['data_options']['modSystemSetting']
    ],


    'rpass' => [
        'fields' => [
            'key' => $config['component']['namespace'].'.robokassa.passwords',
            'value' => 'testPass1||testPass2||pass1||pass2',
            'xtype' => 'textfield',
            'namespace' => $config['component']['namespace'],
            'area' => $config['component']['namespace'].'.main'
        ],
        'options' => $config['data_options']['modSystemSetting']
    ],

    /** сбербанк */

    'sid' => [
        'fields' => [
            'key' => $config['component']['namespace'].'.sberbank.id',
            'value' => '',
            'xtype' => 'textfield',
            'namespace' => $config['component']['namespace'],
            'area' => $config['component']['namespace'].'.main'
        ],
        'options' => $config['data_options']['modSystemSetting']
    ],

    'stest' => [
        'fields' => [
            'key' => $config['component']['namespace'].'.sberbank.is_test',
            'value' => '1',
            'xtype' => 'textfield',
            'namespace' => $config['component']['namespace'],
            'area' => $config['component']['namespace'].'.main'
        ],
        'options' => $config['data_options']['modSystemSetting']
    ],

    'spass' => [
        'fields' => [
            'key' => $config['component']['namespace'].'.sberbank.passwords',
            'value' => 'testPass||pass1',
            'xtype' => 'textfield',
            'namespace' => $config['component']['namespace'],
            'area' => $config['component']['namespace'].'.main'
        ],
        'options' => $config['data_options']['modSystemSetting']
    ],

    /** */

    'shkstatuses' => [
        'fields' => [
            'key' => $config['component']['namespace'].'.shk.statuses',
            'value' => '2||6||7',
            'xtype' => 'textfield',
            'namespace' => $config['component']['namespace'],
            'area' => $config['component']['namespace'].'.main'
        ],
        'options' => $config['data_options']['modSystemSetting']
    ],
    
];
