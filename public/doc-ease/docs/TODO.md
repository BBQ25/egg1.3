# TODO: Rename Uploaded Pictures to Student Format

## Tasks
- [x] Modify includes/upload.php to query students table and rename single uploaded file to {student_number - surname, firstname middlename}.ext format
- [x] Modify includes/upload_multiple.php to query students table once and rename each uploaded file to {student_number - surname, firstname middlename - index}.ext format for multiple files
- [x] Test the upload functionality to ensure renaming works and database is updated correctly
- [x] Handle errors if student not found in database

## Notes
- Assumes students table has columns: student_number, surname, firstname, middlename
- If middlename is null/empty, use only firstname
- For multiple uploads, append -1, -2, etc. to avoid filename conflicts
