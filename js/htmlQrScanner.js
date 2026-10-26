const html5QrCode = new Html5Qrcode("reader");
const qrCodeSuccessCallback = (decodedText, decodedResult) => {
    // document.getElementById('data').innerHTML = decodedText;
    
    if(decodedText == "SETTribe"){
        html5QrCode.stop();
        // window.location = "imageCapture?punch="+$("#punch").val()+"&updatedToSenior="+$("#updatedToSenior").val();
        window.location = "attendance?punch"+$("#punch").val()+"=&updatedToSenior="+$("#updatedToSenior").val();
    }
};
const config = { fps: 10, qrbox: { width: 250, height: 250 } };

// Try exact environment camera first, fall back to any environment camera
html5QrCode.start({ facingMode: { exact: "environment" } }, config, qrCodeSuccessCallback)
    .catch(() => {
        html5QrCode.start({ facingMode: "environment" }, config, qrCodeSuccessCallback)
            .catch(err => console.error("Could not start QR scanner:", err));
    });