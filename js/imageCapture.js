Webcam.set({
    width: '1280',
    height: '720',
    image_format: 'jpeg',
    jpeg_quality: 100,
    force_flash: false,
    flip_horiz: true
});
Webcam.attach('#capture-frame');

let captureFun = () => {
    Webcam.snap( (data_uri) => {
        let videoFrameHeight = $('video').height();
        Webcam.reset();
        document.getElementById('capture-frame').innerHTML = '<img src="'+data_uri+'" class="capture-frame img-fluid" style="height:'+videoFrameHeight+'px"/>';
        $("#captured-date").val(data_uri);
        
        $("#captureBtnDiv").hide();
        $("#recaptureBtnDiv").show();
    });
}

let resetWebCam = () => {
    Webcam.attach('#capture-frame');
    $("#captureBtnDiv").show();
    $("#recaptureBtnDiv").hide();
    frameHeight();
}

let frameHeight = () => {
    $("video, .capture-frame").css({
        'height' : 'auto'
    })    
}
frameHeight();

let diabledFun = () => {
    $("#recaptureBtnDiv button").prop('disabled', true);
    $("#recaptureBtnDiv button[type=submit]").html('<span class="spinner-grow spinner-grow-sm" role="status" aria-hidden="true"></span> Uploading...');
}