{{-- Selection checkbox of a table row; it belongs to the bulk form (form attribute) as items[]. --}}
@props(['value', 'label', 'form', 'disabled' => false])
<td class="col-check"><label class="ap-check"><input type="checkbox" data-select-row name="items[]" value="{{ $value }}" form="{{ $form }}" aria-label="{{ $label }}" @disabled($disabled)></label></td>
