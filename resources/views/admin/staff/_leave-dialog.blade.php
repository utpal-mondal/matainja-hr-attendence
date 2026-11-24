{{--
    "Add leave" pop-up (approved straight away). Needs $leaveTypes and $leaveDate (Y-m-d default).
    Employee page: pass $leaveStaff (fixed employee) and optionally $leaveBalances (type id => "8 left" / "2 used").
    Attendance list: pass $leaveReturnTo = 'attendance'; buttons with data-leave-action point it at an employee.
--}}
@php
    $leaveStaff ??= null;
    $leaveBalances ??= [];
    $leaveReturnTo ??= null;
    $retryId = $errors->leave->any() ? (int) old('leave_staff_id') : null;
    $retryName = $retryId ? old('leave_staff_name') : null;
    $action = $leaveStaff ? route('admin.staff.leave.store', $leaveStaff) : ($retryId ? route('admin.staff.leave.store', $retryId) : '');
    $leaveOld = fn (string $field, mixed $default = null) => $errors->leave->any() ? old($field, $default) : $default;
    $leaveErr = fn (string $field) => $errors->leave->first($field);
@endphp
<dialog id="leave-dialog" aria-labelledby="leave-title" @if ($errors->leave->any()) data-open-on-load @endif>
    <form method="POST" action="{{ $action }}" class="card-body" id="leave-form">
        @csrf
        @if ($leaveReturnTo)<input type="hidden" name="return_to" value="{{ $leaveReturnTo }}">@endif
        <input type="hidden" name="leave_staff_id" id="leave-staff-id" value="{{ $leaveStaff?->id ?? $retryId }}">
        <input type="hidden" name="leave_staff_name" id="leave-staff-name" value="{{ $leaveStaff?->full_name ?? $retryName }}">
        <h2 id="leave-title">Add leave</h2>
        <p class="muted" style="margin:4px 0 14px"><strong id="leave-name">{{ $leaveStaff?->full_name ?? $retryName }}</strong> · approved straight away and counted against their leave balance.</p>
        <div class="form-grid">
            <div class="field wide">
                <label for="leave_type">Leave type *</label>
                <select id="leave_type" name="leave_type_id" required class="{{ $leaveErr('leave_type_id') ? 'is-invalid' : '' }}">
                    @foreach ($leaveTypes as $type)
                        <option value="{{ $type->id }}" @selected((int) $leaveOld('leave_type_id') === $type->id)>
                            {{ $type->name }}{{ $type->paid ? '' : ' (unpaid)' }}{{ isset($leaveBalances[$type->id]) ? ' · '.$leaveBalances[$type->id] : '' }}
                        </option>
                    @endforeach
                </select>
                @if ($leaveErr('leave_type_id'))<div class="error">{{ $leaveErr('leave_type_id') }}</div>@endif
            </div>
            <div class="field">
                <label for="leave_from">From *</label>
                <input id="leave_from" type="date" name="from_date" value="{{ $leaveOld('from_date', $leaveDate) }}" required class="{{ $leaveErr('from_date') ? 'is-invalid' : '' }}">
                @if ($leaveErr('from_date'))<div class="error">{{ $leaveErr('from_date') }}</div>@endif
            </div>
            <div class="field">
                <label for="leave_to">To *</label>
                <input id="leave_to" type="date" name="to_date" value="{{ $leaveOld('to_date', $leaveDate) }}" required class="{{ $leaveErr('to_date') ? 'is-invalid' : '' }}">
                @if ($leaveErr('to_date'))<div class="error">{{ $leaveErr('to_date') }}</div>@endif
            </div>
            <div class="field wide">
                <label class="check"><input type="checkbox" name="partial_day" value="1" @checked($leaveOld('partial_day'))> Half day (from and to must be the same date)</label>
                @if ($leaveErr('partial_day'))<div class="error">{{ $leaveErr('partial_day') }}</div>@endif
            </div>
            <div class="field wide">
                <label for="leave_reason">Reason</label>
                <input id="leave_reason" type="text" name="reason" maxlength="2000" value="{{ $leaveOld('reason') }}" placeholder="e.g. Fever, called in sick">
            </div>
        </div>
        <p class="hint" style="margin:0 0 12px">Days are counted from the start to the end date, the same way as requests from the app.</p>
        <div class="actions" style="justify-content:flex-end">
            <button type="button" class="btn" data-close-dialog>Cancel</button>
            <button type="submit" class="btn primary">Add leave</button>
        </div>
    </form>
</dialog>

@unless ($leaveStaff)
    @push('scripts')
    <script>
        // Point the shared "Add leave" pop-up at the employee whose button was clicked.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-leave-action]');
            if (!btn) return;
            var form = document.getElementById('leave-form');
            form.action = btn.getAttribute('data-leave-action');
            document.getElementById('leave-staff-id').value = btn.getAttribute('data-leave-staff-id');
            document.getElementById('leave-staff-name').value = btn.getAttribute('data-leave-name');
            document.getElementById('leave-name').textContent = btn.getAttribute('data-leave-name');
            form.querySelectorAll('.error').forEach(function (el) { el.remove(); });
            form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        });
    </script>
    @endpush
@endunless
