function previewFile(input) {
    const preview = document.getElementById('previewArea');
    const imagePreview = document.getElementById('imagePreview');

    if (input.files && input.files[0]) {
        const file = input.files[0];
        const reader = new FileReader();

        reader.onload = function (e) {
            if (file.type === 'application/pdf') {
                imagePreview.innerHTML = '<div style="padding:40px;text-align:center;background:#f9fafb"><div style="font-size:48px;margin-bottom:8px">📄</div><div style="font-weight:600">' + file.name + '</div></div>';
            } else {
                imagePreview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:auto">';
            }
            preview.style.display = 'block';
        };

        reader.readAsDataURL(file);
    }
}

document.getElementById('receiptUploadForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const submitBtn = document.getElementById('submitBtn');
    const formMessage = document.getElementById('formMessage');
    const formData = new FormData(this);

    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.textContent = 'Uploading...';
    formMessage.style.display = 'none';

    try {
        const response = await fetch('/?page=receipts-handler', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            formMessage.style.display = 'block';
            formMessage.style.background = '#ecfdf5';
            formMessage.style.border = '1px solid #a7f3d0';
            formMessage.style.color = '#065f46';
            formMessage.textContent = result.message;

            // Redirect after short delay
            setTimeout(() => {
                window.location.href = result.redirect;
            }, 1000);
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        formMessage.style.display = 'block';
        formMessage.style.background = '#fee2e2';
        formMessage.style.border = '1px solid #fca5a5';
        formMessage.style.color = '#991b1b';
        formMessage.textContent = error.message || 'Failed to upload receipt';

        submitBtn.disabled = false;
        submitBtn.textContent = 'Upload Receipt';
    }
});