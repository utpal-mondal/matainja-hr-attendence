<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'initial_password' => $this->when($request->user()?->isAdmin(), $this->initial_password),
            'employee_code' => $this->employee_code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'position' => $this->position,
            'department' => $this->whenLoaded('department', fn () => $this->department?->only('id', 'name', 'code')),
            'designation' => $this->whenLoaded('designation', fn () => $this->designation?->only('id', 'title')),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->only('id', 'name', 'code')),
            'team' => $this->whenLoaded('team', fn () => $this->team?->only('id', 'name')),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id' => $this->manager->id,
                'name' => $this->manager->full_name,
            ] : null),
            'manager_id' => $this->manager_id,
            'department_id' => $this->department_id,
            'designation_id' => $this->designation_id,
            'branch_id' => $this->branch_id,
            'team_id' => $this->team_id,
            // Today's shift, so every employee shows their real hours (null = not assigned).
            'shift' => $this->whenLoaded('currentShiftAssignment', fn () => $this->currentShiftAssignment?->shift?->only('id', 'name', 'start_time', 'end_time', 'grace_minutes')),
            'joining_date' => $this->joining_date?->toDateString(),
            'employment_status' => $this->employment_status,
        ];
    }
}
