{{-- Fields shared by "new tier" and "edit tier". --}}
<div class="row">
    <label class="field"><span>Name</span>
        <input type="text" name="name" value="{{ old('name', $tier->name) }}" required maxlength="100" placeholder="Gold">
        @error('name')<span class="error">{{ $message }}</span>@enderror
    </label>
    <label class="field"><span>Discount %</span>
        <input type="text" inputmode="decimal" name="discount_percent" value="{{ old('discount_percent', $tier->discount_percent !== null ? rtrim(rtrim((string) $tier->discount_percent, '0'), '.') : '') }}" required placeholder="15">
        @error('discount_percent')<span class="error">{{ $message }}</span>@enderror
    </label>
    <label class="field"><span>Priority</span>
        <input type="number" name="priority" min="0" max="65535" value="{{ old('priority', $tier->priority ?? 0) }}">
        Higher wins when a customer is in several tiers.
        @error('priority')<span class="error">{{ $message }}</span>@enderror
    </label>
</div>
<label class="field"><span>Description</span>
    <textarea name="description" maxlength="1000" placeholder="Who belongs here, e.g. resellers ordering over 50 000 kr a year">{{ old('description', $tier->description) }}</textarea>
</label>
@if ($tier->exists)
    <label class="field"><span>Short name (API)</span>
        <input type="text" name="slug" value="{{ old('slug', $tier->slug) }}" maxlength="100">
        Used by the tier API. Change it only if nothing uses the old one.
        @error('slug')<span class="error">{{ $message }}</span>@enderror
    </label>
@endif
<label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $tier->is_active ?? true))> Active (customers in an inactive tier pay list price)</label>
