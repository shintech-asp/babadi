<?php

function pestifyEmployeePositionOptions(): array
{
    return [
        'Pest Control Technician',
        'Senior Pest Control Technician',
        'Field Supervisor',
        'Operations Coordinator',
        'Customer Service Representative',
        'Customer Relationship Officer',
        'Sales Representative',
        'Finance Assistant',
        'Payroll Assistant',
        'HR Assistant',
        'HR Manager',
        'Finance Manager',
        'CRM Manager',
        'Administrative Assistant',
        'Driver',
        'Warehouse Assistant',
    ];
}

/**
 * Which positions and staff types make sense for each department, so the
 * Add Employee form can filter Position and suggest Staff Type as soon as
 * Department is picked. "Human Resources" and "Finance" are desk-only
 * departments; "Customer Relationship" covers both office CRM staff and
 * field technicians, so both staff types stay selectable there.
 */
function pestifyEmployeeDepartmentCatalog(): array
{
    return [
        'Human Resources' => [
            'positions'           => ['HR Assistant', 'HR Manager', 'Administrative Assistant'],
            'allowed_staff_types' => ['office'],
        ],
        'Finance' => [
            'positions'           => ['Finance Assistant', 'Payroll Assistant', 'Finance Manager', 'Administrative Assistant'],
            'allowed_staff_types' => ['office'],
        ],
        'Customer Relationship' => [
            'positions' => [
                'Operations Coordinator', 'Customer Service Representative', 'Customer Relationship Officer',
                'Sales Representative', 'CRM Manager',
            ],
            'allowed_staff_types' => ['office'],
        ],
        // Dedicated department for identifying/managing field technicians —
        // every position here is field work, so Staff Type is locked to 'field'.
        'Field Operations' => [
            'positions'           => [
                'Pest Control Technician', 'Senior Pest Control Technician', 'Field Supervisor',
                'Driver', 'Warehouse Assistant',
            ],
            'allowed_staff_types' => ['field'],
        ],
    ];
}

/**
 * Suggested Staff Type per position (used to auto-select once a position is
 * chosen). Positions not listed here default to 'office'.
 */
function pestifyPositionDefaultStaffType(): array
{
    return [
        'Pest Control Technician'        => 'field',
        'Senior Pest Control Technician' => 'field',
        'Field Supervisor'               => 'field',
        'Driver'                         => 'field',
        'Warehouse Assistant'            => 'field',
    ];
}

function pestifyEmploymentTypeOptions(): array
{
    return [
        'regular' => 'Regular',
        'probationary' => 'Probationary',
        'contractual' => 'Contractual',
        'part_time' => 'Part-time',
    ];
}
