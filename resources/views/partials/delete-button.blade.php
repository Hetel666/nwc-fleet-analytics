<form method="POST" action="{{ $action }}" onsubmit="return confirm('Silmək istəyirsiniz?')" class="d-inline">
    @csrf
    @method('DELETE')
    <button class="btn btn-sm btn-outline-danger" title="{{ __('app.delete') }}">
        <i class="bi bi-trash"></i>
    </button>
</form>
