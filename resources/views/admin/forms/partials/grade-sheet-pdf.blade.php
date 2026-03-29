@php
    $title = $context['courseCode'] . ' - ' . $context['courseTitle'];
@endphp

<style>
  body {
    font-family: helvetica, sans-serif;
    font-size: 9pt;
    color: #172b4d;
  }

  .heading {
    text-align: center;
    margin-bottom: 6px;
  }

  .heading h1 {
    font-size: 14pt;
    margin: 0 0 2px 0;
  }

  .heading p {
    margin: 0;
    font-size: 9pt;
  }

  .meta {
    width: 100%;
    margin-bottom: 8px;
    border-collapse: collapse;
  }

  .meta td {
    border: 1px solid #cbd5e1;
    padding: 4px 6px;
    vertical-align: top;
  }

  .meta .label {
    font-weight: bold;
    width: 20%;
    background: #f8fafc;
  }

  .grades {
    width: 100%;
    border-collapse: collapse;
  }

  .grades th,
  .grades td {
    border: 1px solid #cbd5e1;
    padding: 4px 5px;
  }

  .grades th {
    background: #eef2ff;
    font-weight: bold;
    text-align: center;
  }

  .text-center {
    text-align: center;
  }

  .summary {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
  }

  .summary td {
    border: 1px solid #cbd5e1;
    padding: 4px 6px;
  }

  .summary .label {
    width: 20%;
    font-weight: bold;
    background: #f8fafc;
  }
</style>

<div class="heading">
  <h1>APEWSD Grade Sheet</h1>
  <p>{{ $title }}</p>
  <p>Generated {{ $generatedAt->format('F d, Y h:i A') }}</p>
</div>

<table class="meta">
  <tr>
    <td class="label">Course Code</td>
    <td>{{ $context['courseCode'] }}</td>
    <td class="label">School Year</td>
    <td>{{ $context['schoolYear'] }}</td>
  </tr>
  <tr>
    <td class="label">Course Title</td>
    <td>{{ $context['courseTitle'] }}</td>
    <td class="label">Semester</td>
    <td>{{ $context['semester'] }}</td>
  </tr>
  <tr>
    <td class="label">Schedule</td>
    <td>{{ $context['scheduleLabel'] }}</td>
    <td class="label">Section</td>
    <td>{{ $context['sectionLabel'] }}</td>
  </tr>
  <tr>
    <td class="label">Schedule ID</td>
    <td colspan="3">{{ $context['scheduleId'] }}</td>
  </tr>
</table>

<table class="grades">
  <thead>
    <tr>
      <th style="width: 5%;">#</th>
      <th style="width: 18%;">Student No</th>
      <th style="width: 39%;">Name</th>
      <th style="width: 9%;">MT</th>
      <th style="width: 9%;">FT</th>
      <th style="width: 9%;">AVG</th>
      <th style="width: 11%;">INC</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($rows as $row)
      <tr>
        <td class="text-center">{{ $row['no'] }}</td>
        <td class="text-center">{{ $row['student_no'] }}</td>
        <td>{{ $row['name'] }}</td>
        <td class="text-center">{{ $row['mt'] }}</td>
        <td class="text-center">{{ $row['ft'] }}</td>
        <td class="text-center">{{ $row['avg'] }}</td>
        <td class="text-center">{{ $row['inc'] }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<table class="summary">
  <tr>
    <td class="label">Total Students</td>
    <td>{{ $summary['total'] }}</td>
    <td class="label">With Grades</td>
    <td>{{ $summary['with_grades'] }}</td>
  </tr>
  <tr>
    <td class="label">Without Grades</td>
    <td>{{ $summary['without_grades'] }}</td>
    <td class="label">INC</td>
    <td>{{ $summary['inc'] }}</td>
  </tr>
  <tr>
    <td class="label">Passed</td>
    <td>{{ $summary['passed'] }}</td>
    <td class="label">Failed</td>
    <td>{{ $summary['failed'] }}</td>
  </tr>
</table>

