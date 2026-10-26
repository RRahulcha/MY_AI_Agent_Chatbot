$(document).ready(() => {
    let dataTable = $('#projectMasterTable').DataTable({
        'order': [[2, 'desc']],
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        'ajax': {
            'url':'../ajax/projectMaster?dataTable='
        },
        'responsive': true,
        'columns': [
            {data:'projectName'},
            {data:'client'},
            {data:'modificationDate'},
            {data:'deadLine'},  // New column for Deadline
            {data:'priority'},  // New column for Priority
            {data:'status'},
            {data:'action'},
        ],
        'columnDefs': [
            { "orderable": false, "targets": [6] }
        ]
    });

    dataTable.draw();
});
    
let edit = (projectId) => {
    $("#submitBtn").html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <span class="sr-only">Loading...</span>');
    $("#submitBtn").prop('disabled', true);
    $.ajax({
        type : 'GET',
        url  : '../ajax/projectMaster',
        data : {projectId:projectId, edit:''},
        success: function(result) {
            let data = JSON.parse(result);
    
            $("#projectName").val(data.projectName);
            $("#client").val(data.client);
            $("#deadLine").val(data.deadLine);
            $("#priority").val(data.priority);
            
            if(data.status == 1){
                $("#statusActive").click();
            } else {
                $("#statusInactive").click();
            }
            $("#cancelBtn").show();
            $("#pageTitle").html('Update Project');
            $("#submitBtn").html('Update');
            $("#submitBtn").prop('disabled', false);
            $("#submitBtn").attr({name:'update', value: projectId});
        }
    });
};