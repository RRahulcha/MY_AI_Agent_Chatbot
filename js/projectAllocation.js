$(document).ready(() => {
    let dataTable = $('#allocatedProjectTable').DataTable({
        // 'order': [[0, 'desc']],
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        'ajax': {
            'url':'../ajax/projectAllocation?dataTable='
        },
        'responsive': true,
        'columns': [
            {data:'projectName'},
            {data:'fullName'},
            {data:'action'},
        ],
        'columnDefs': [
            { "orderable": false, "targets": [2] }
        ]
    });
    
    dataTable.draw();
});