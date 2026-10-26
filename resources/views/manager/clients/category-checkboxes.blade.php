<fieldset class="grid gap-3 sm:grid-cols-2">
    <legend class="mb-3 text-sm font-semibold">Catégories autorisées</legend>
    @foreach (\App\Services\Crm\PortalCategoryAccess::catalogue() as $key => $category)
        <label class="flex items-center gap-2 text-sm" for="{{ $categoryPrefix }}-{{ $key }}">
            <input type="checkbox" id="{{ $categoryPrefix }}-{{ $key }}" name="categories[]" value="{{ $key }}" @checked(in_array($key, $selectedCategories, true))>
            {{ $category['label'] }}
        </label>
    @endforeach
</fieldset>
