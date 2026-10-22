# Completion states

What `format_rumbos_get_course_state` returns for an activity's `completion`, next to what Moodle
itself shows for the same student, and how fresh it is. Measured, not read off the code, on the
development site (Moodle 5.2.4, PostgreSQL 17, PHP 8.3).

## How it was measured

A throwaway Rumbos course with five quizzes and one student. Each quiz has one numerical question,
a maximum grade of 100 and a **grade to pass of 80**. They differ only in their completion condition.
The student's attempts were made through Moodle's own quiz classes (`quiz_attempt::process_submit`
and `process_grade_submission`), so Moodle's event observers ran as for a real submission.

After every step the state was read in a **separate PHP process** from the one that made the attempt,
as an AJAX request is. Moodle's side comes from `core_completion\cm_completion_details`, the class
behind the completion dialog of the course page: its overall state and the state of each rule.

- Rule or overall state `0` is incomplete, `1` complete, `2` complete with a passing grade, `3` failed.
  The dialog lists a rule as "To do" (0), "Done" (1 and 2) or "Failed" (3).
- The state column is what the plugin returned. The stored column is the `completionstate` of the
  student's row in `course_modules_completion`.

## The matrix

| Completion condition of the quiz | Step | State returned | Stored | Moodle's overall state | Moodle's rules |
| --- | --- | --- | --- | --- | --- |
| None | any | `none` | no row | no completion information | none |
| View | before opening | `incomplete` | no row | 0 | View: 0 |
| View | after opening it | `complete` | 1 | 1 | View: 1 |
| Receive a grade | before any attempt | `incomplete` | no row | 0 | Receive a grade: 0 |
| Receive a grade | attempt 1, wrong answer (grade 0) | `failed` | 3 | 3 | Receive a grade: 3 |
| Receive a grade | attempt 2, right answer (grade 100) | `complete` | 2 | 2 | Receive a grade: 2 |
| Receive a passing grade | before any attempt | `incomplete` | no row | 0 | Receive a grade: 0; passing grade: 0 |
| Receive a passing grade | attempt 1, wrong answer | `failed` | 3 | 3 | Receive a grade: 1; passing grade: 3 |
| Receive a passing grade | attempt 2, right answer | `complete` | 2 | 2 | Receive a grade: 2; passing grade: 2 |
| Passing grade or all attempts used (2 attempts) | before any attempt | `incomplete` | no row | 0 | grade: 0; passing grade: 0; all attempts: 0 |
| Passing grade or all attempts used (2 attempts) | attempt 1, wrong answer | `incomplete` | no row | 0 | grade: 1; passing grade: 3; all attempts: 0 |
| Passing grade or all attempts used (2 attempts) | attempt 2, wrong answer | `failed` | 3 | 3 | grade: 1; passing grade: 3; all attempts: 1 |

Reading the failed cases:

- **The plugin and Moodle always agree.** `failed` is Moodle's own overall state (`3`), mapped as it is.
  There is no row in this table where the plugin says something else than the dialog's overall state.
- **"Receive a grade" fails below the grade to pass.** When the quiz has a grade to pass, getting a
  grade under it is stored as failed, even though the condition is only "receive a grade". With no
  grade to pass set the rule has nothing to fail against; that case was not measured.
- **`failed` is not final.** A later attempt that passes turns the same rows into `complete` (the
  second and third rows of "Receive a grade" and "Receive a passing grade").
- **Using up the attempts does not rescue the quiz.** In the last row the "all attempts" rule is met
  (1) but the passing-grade rule is failed (3), and Moodle's overall state is failed. With no attempts
  left it stays so. The map shows `failed` for it.
- **The row appears with the first attempt only when something is decided.** After a wrong first
  attempt of the last quiz there is no row yet: nothing is decided, so the state is `incomplete`.

## Does anything wait for a scheduled task?

No. For all five conditions the quiz's attempt-submitted observer runs inside the request that submits
the attempt, so the state read after that request already has the result. The one exception is an
attempt left open past its time limit: the scheduled task `mod_quiz\task\update_overdue_attempts`
closes it later, and completion changes then, with nobody navigating anywhere. The map shows it at
the next read.

## Cache behaviour

Moodle keeps the current user's completion in the application cache `core/completion` (key
`{userid}_{courseid}`, one hour) and reads it from there for that user. Writes update the cache of the
server that handled them. On a site with several web servers and a cache store that is not shared, a
server can keep an entry that another server has already replaced, so the map would show an activity
as unfinished for up to an hour after it was finished.

`format_rumbos_get_course_state` therefore deletes the user's entry before it reads, and reads the whole
course's rows for that user in one query, which also leaves a fresh entry for the next Moodle page.
`tests/local/course_state_test.php` plants a stale entry and checks that the state is not taken from it;
the test fails if the deletion is removed.

Completion of **other** users is never cached by Moodle, and the function has no user parameter: it
always answers for the signed-in user.

## What a read costs

Every state read now starts from the database. Measured with a course of 30 activities (page, URL,
quiz and forum, each with "view" completion, half of them viewed), 20 calls in one PHP process:

| | Database reads | Time, median (min to max) |
| --- | --- | --- |
| Before: served from Moodle's cache | 1 | 3 to 9 ms (2 to 22 ms) |
| Cold, per-activity reads | 61 | 97 to 115 ms (52 to 186 ms) |
| Now: cache dropped, whole course in one query | 32 | 39 to 41 ms (28 to 63 ms) |
| First call of a fresh process, now | 39 | 100 to 160 ms |

- Of the 32 reads, 30 are Moodle's own check of the "viewed" flag for each activity, made inside
  `completion_info::get_data`. The plugin cannot batch them through the public API. Reading
  `course_modules_completion` directly would be about 2 reads, but would stop using Moodle's own
  function; it was not done.
- This is the function, not the request: an AJAX call also pays Moodle's bootstrap and session.
- The cost scales with the number of activities (about one read each). A course of 100 would be
  roughly three times the 30-activity figures.
- Polling (see [HOST-CONTRACT.md](HOST-CONTRACT.md)) adds one such call every 15 seconds per student
  with a SCORM or H5P popup open and the tab visible. A thousand of them at once is about 67 calls a
  second, about 2,100 database reads a second in all (32 reads each), which is a load to plan for;
  the list of polled modules (`pollmodules`) can be emptied to turn polling off.
