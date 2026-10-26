@include('crm.dossiers.table')
<div class="gc-dossier-pagination">
    {{ $dossiers->links('pagination::tailwind', ['pageSizeControl' => 'crm.dossiers.page-size']) }}
</div>
