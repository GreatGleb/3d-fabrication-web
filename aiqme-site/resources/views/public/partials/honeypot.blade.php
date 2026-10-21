{{-- Honeypot форм W-09: поле вне экрана и вне порядка Tab; человек его не видит и не заполняет, заполненное — отказ. --}}
<div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden">
    <label>Website <input type="text" name="{{ \App\Http\Controllers\Public\FormController::HONEYPOT }}" value="" tabindex="-1" autocomplete="off"></label>
</div>
