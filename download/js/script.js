document.addEventListener('DOMContentLoaded', function() {
    console.log('SETTribe AMS Landing Page Loaded');

    // Example interaction for download button
    const downloadBtns = document.querySelectorAll('a[href="#"]');
    
    downloadBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            if(this.getAttribute('href') === '#') {
                e.preventDefault();
                alert('Download link will be available soon!');
            }
        });
    });
});
