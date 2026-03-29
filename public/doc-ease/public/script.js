document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            uploadFile(position.coords.latitude, position.coords.longitude);
        }, function() {
            uploadFile(null, null);
        });
    } else {
        uploadFile(null, null);
    }
});

function uploadFile(latitude, longitude) {
    let fileInput = document.getElementById('file');
    if (fileInput.files.length === 0) {
        alert('Please select a file to upload.');
        return;
    }
    let file = fileInput.files[0];
    let studentNo = document.getElementById('studentNo').value;
    let notes = document.getElementById('notes').value;
    let formData = new FormData();
    formData.append('file', file);
    formData.append('studentNo', studentNo);
    formData.append('notes', notes);
    formData.append('latitude', latitude);
    formData.append('longitude', longitude);

    // Checklist data
    let checklist = [];
    document.querySelectorAll('input[name="checklist[]"]:checked').forEach(function(checkbox) {
        checklist.push(checkbox.value);
    });
    formData.append('checklist', JSON.stringify(checklist));


    let modal = new bootstrap.Modal(document.getElementById('uploadModal'));
    let modalProgressBar = document.getElementById('modalProgressBar');
    let etaText = document.getElementById('etaText');
    let pingText = document.getElementById('pingText');
    let modalStatus = document.getElementById('modalStatus');

    modal.show();

    let xhr = new XMLHttpRequest();
    let startTime = Date.now();

    // Ping simulation
    let pingStartTime = Date.now();
    let pingXhr = new XMLHttpRequest();
    pingXhr.open('GET', 'includes/ping.php', true);
    pingXhr.onload = function() {
        if (pingXhr.status === 200) {
            let ping = Date.now() - pingStartTime;
            pingText.textContent = 'Ping: ' + ping + ' ms';
        }
    };
    pingXhr.send();


    xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
            let percentComplete = (e.loaded / e.total) * 100;
            modalProgressBar.style.width = percentComplete + '%';
            modalProgressBar.textContent = Math.round(percentComplete) + '%';

            // ETA calculation
            let elapsedTime = (Date.now() - startTime) / 1000; // in seconds
            let uploadSpeed = e.loaded / elapsedTime; // bytes per second
            let remainingBytes = e.total - e.loaded;
            let remainingTime = remainingBytes / uploadSpeed; // in seconds

            let hours = Math.floor(remainingTime / 3600);
            let minutes = Math.floor((remainingTime % 3600) / 60);
            let seconds = Math.floor(remainingTime % 60);

            etaText.textContent = 'ETA: ' +
                (hours > 0 ? hours + 'h ' : '') +
                (minutes > 0 ? minutes + 'm ' : '') +
                seconds + 's';
        }
    });

    xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            modal.hide();
            if (xhr.status === 200) {
                document.getElementById('status').innerHTML = '<div class="alert alert-success">' + xhr.responseText + '</div>';
                loadFileList(); // Refresh file list
            } else {
                document.getElementById('status').innerHTML = '<div class="alert alert-danger">File upload failed.</div>';
            }
        }
    };

    xhr.open('POST', 'includes/upload.php', true);
    xhr.send(formData);
}

// Variables for search and pagination
let currentSearch = '';
let currentPage = 1;

// Function to load the file list
function loadFileList(search = '', page = 1) {
    currentSearch = search;
    currentPage = page;
    let xhr = new XMLHttpRequest();
    xhr.open('GET', 'includes/list_files.php?search=' + encodeURIComponent(search) + '&page=' + page, true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            document.getElementById('fileList').innerHTML = xhr.responseText;
            attachDeleteEvents();
            attachPaginationEvents();
        } else {
            document.getElementById('fileList').innerHTML = '<div class="alert alert-danger">Failed to load files.</div>';
        }
    };
    xhr.send();
}

// Function to attach pagination event listeners
function attachPaginationEvents() {
    let pageLinks = document.querySelectorAll('.page-link[data-page]');
    pageLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            let page = parseInt(this.getAttribute('data-page'));
            loadFileList(currentSearch, page);
        });
    });
}

// Function to attach delete button event listeners
function attachDeleteEvents() {
    let deleteButtons = document.querySelectorAll('.delete-btn');
    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            if (confirm('Are you sure you want to delete this file?')) {
                let fileId = this.getAttribute('data-id');
                deleteFile(fileId);
            }
        });
    });
}

// Function to delete a file
function deleteFile(fileId) {
    let formData = new FormData();
    formData.append('id', fileId);

    let xhr = new XMLHttpRequest();
    xhr.open('POST', 'includes/delete_file.php', true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            alert(xhr.responseText);
            loadFileList();
        } else {
            alert('Failed to delete file.');
        }
    };
    xhr.send(formData);
}

document.getElementById('searchInput').addEventListener('input', function() {
    let searchTerm = this.value.trim();
    loadFileList(searchTerm, 1);
});

// Load file list on page load
document.addEventListener('DOMContentLoaded', function() {
    loadFileList();
});