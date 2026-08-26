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

function pestifyEmploymentTypeOptions(): array
{
    return [
        'regular' => 'Regular',
        'probationary' => 'Probationary',
        'contractual' => 'Contractual',
        'part_time' => 'Part-time',
    ];
}
