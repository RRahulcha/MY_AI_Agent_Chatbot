//
//QR scanner
//

if($(location).attr("href").split('/').slice(-1)[0] == "qrCodeScanner"){
    const html5QrCode = new Html5Qrcode("reader");
    const qrCodeSuccessCallback = (decodedText, decodedResult) => {
        // document.getElementById('data').innerHTML = decodedText;
        
        if(decodedText == "amshealthjalnaqrcodesystem"){
            html5QrCode.stop();
            window.location = "imageCapture?punch=<?php echo $_GET['punch'] ?>";
        }
    };
    const config = { fps: 10, qrbox: { width: 250, height: 250 } };
    
    // Try exact environment camera first, fall back to any environment camera
    html5QrCode.start({ facingMode: { exact: "environment" } }, config, qrCodeSuccessCallback)
        .catch(() => {
            html5QrCode.start({ facingMode: "environment" }, config, qrCodeSuccessCallback)
                .catch(err => console.error("Could not start QR scanner:", err));
        });
}

//
//imageCapture
//
if($(location).attr("href").split('/').slice(-1)[0] == "imageCapture"){
Webcam.set({
    width: '300',
    height: '200',
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
}