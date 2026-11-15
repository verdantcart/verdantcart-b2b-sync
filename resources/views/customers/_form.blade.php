{{-- Fields shared by "new customer" and the customer page. --}}
<div class="row">
    <label class="field"><span>Company</span>
        <input type="text" name="company_name" value="{{ old('company_name', $customer->company_name) }}" required maxlength="255">
        @error('company_name')<span class="error">{{ $message }}</span>@enderror
    </label>
    <label class="field"><span>E-mail</span>
        <input type="email" name="email" value="{{ old('email', $customer->email) }}" required maxlength="255">
        Orders from this address are matched to the customer if they aren't logged in.
        @error('email')<span class="error">{{ $message }}</span>@enderror
    </label>
</div>
<div class="row">
    <label class="field"><span>Org. number</span>
        <input type="text" name="org_number" value="{{ old('org_number', $customer->org_number) }}" maxlength="20" placeholder="556000-0000">
    </label>
    <label class="field"><span>VAT number</span>
        <input type="text" name="vat_number" value="{{ old('vat_number', $customer->vat_number) }}" maxlength="30" placeholder="SE556000000001">
    </label>
    <label class="field"><span>Country</span>
        <input type="text" name="country_code" value="{{ old('country_code', $customer->country_code) }}" maxlength="2" required>
        @error('country_code')<span class="error">{{ $message }}</span>@enderror
    </label>
    <label class="field"><span>Currency</span>
        <input type="text" name="currency" value="{{ old('currency', $customer->currency) }}" maxlength="3" required>
        @error('currency')<span class="error">{{ $message }}</span>@enderror
    </label>
</div>
<div class="row">
    <label class="field"><span>WooCommerce customer ID</span>
        <input type="text" inputmode="numeric" name="woo_customer_id" value="{{ old('woo_customer_id', $customer->woo_customer_id) }}" placeholder="Not linked">
        Shown in WooCommerce → Customers. When they log in to the shop they see their B2B prices.
        @error('woo_customer_id')<span class="error">{{ $message }}</span>@enderror
    </label>
    <label class="field"><span>Fortnox customer number</span>
        <input type="text" name="fortnox_customer_number" value="{{ old('fortnox_customer_number', $customer->fortnox_customer_number) }}" maxlength="50" placeholder="Not in Fortnox">
        Their orders are created on this Fortnox customer.
        @error('fortnox_customer_number')<span class="error">{{ $message }}</span>@enderror
    </label>
</div>
<label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $customer->is_active))> Active (an inactive customer pays list price)</label>
