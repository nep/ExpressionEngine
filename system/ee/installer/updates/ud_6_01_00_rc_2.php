<?php

/**
 * This source file is part of the open source project
 * ExpressionEngine (https://expressionengine.com)
 *
 * @link      https://expressionengine.com/
 * @copyright Copyright (c) 2003-2021, Packet Tide, LLC (https://www.packettide.com)
 * @license   https://expressionengine.com/license Licensed under Apache License, Version 2.0
 */

namespace ExpressionEngine\Updater\Version_6_1_0_rc_2;

/**
 * Update
 */
class Updater
{
    public $version_suffix = '';

    /**
     * Do Update
     *
     * @return TRUE
     */
    public function do_update()
    {
        $steps = new \ProgressIterator([
            'addPendingRoleToMember',
        ]);

        foreach ($steps as $k => $v) {
            $this->$v();
        }

        return true;
    }

    private function addPendingRoleToMember()
    {
        if (!ee()->db->field_exists('pending_role_id', 'members')) {
            ee()->smartforge->add_column(
                'members',
                [
                    'pending_role_id' => [
                        'type' => 'int',
                        'constraint' => 10,
                        'default' => '0',
                        'null' => false
                    ]
                ]
            );
        }
    }
}

// EOF
