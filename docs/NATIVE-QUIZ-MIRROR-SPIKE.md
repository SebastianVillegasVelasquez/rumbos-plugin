# Native quiz mirror: feasibility spike

**Verdict: GO.** Everything the contract asks for works on Moodle 5.2.4+ with PostgreSQL 17, with public Moodle code
and no schema of Moodle's own touched. The spike ran in PHPUnit (generators, real question engine, real statistics)
and, for the one thing PHPUnit cannot show, on the dev site's database.

What was and was not verified is stated per item. Paths are relative to Moodle's `public/` directory. The backend has
**not published** an export contract: every exam below is a **fixture shaped like the B2 form A** of the service's
content (`tests/fixtures/mirror_b2_form.php`). When the contract exists only the adapter that reads it changes.

## Rules the design must keep (and how)

1. **The service decides the score and the pass.** The mirror writes the service's marks onto the Moodle attempt
   (below) and never reads Moodle's total to decide anything. The mirror quiz has no grade to pass and no
   completion rule, so Moodle's inclusive "grade to pass" cannot grant anything. The existing passback is still the
   only thing that completes a course.
2. **One grade in the gradebook.** See [the gradebook decision](#gradebook-decision).
3. **Students cannot take it.** Hidden, no completion, and the attempt capability prohibited (below).
4. **Created from a published version, one quiz per version.** Questions are pinned to their version.
5. **Idempotent and off by default.**

## 1. Creating the quiz and its questions

| Step | API used | Public? | Notes |
| --- | --- | --- | --- |
| Quiz module, hidden | `add_moduleinfo($moduleinfo, $course)` (`course/modlib.php`) | Public function, not `@api`-tagged. It is what the web services and the data generator call. | `visible = 0`, `visibleoncoursepage = 0`, `completion = 0`, `grade = 0`. Worked on the first call. |
| Question category | `question_get_default_category($contextid, true)` (`lib/questionlib.php`) | Public function. | In Moodle 5 a quiz owns a question bank in its own module context; the category is created there, so deleting the quiz removes the questions (checked: after `course_delete_module` no attempts and no categories remain). |
| Questions | `question_bank::get_qtype($qtype)->save_question($question, $form)` (`question/type/questiontypebase.php`) | Public method; the same call the import formats use. | One question per exam item. |
| Add to the quiz | `quiz_add_quiz_question($questionid, $quiz, 0, $maxmark)` then `grade_calculator::recompute_quiz_sumgrades()` | Public functions. | Sum of marks came to 100.0000001 before the recompute and 100.00000 after (the quiz stores five decimals). |
| Pin the version | `\mod_quiz\structure::update_slot_version($slotid, $version)` | Public method. | See risks. |

**Question-bank versioning.** A new slot references the question *entry* with `question_references.version = NULL`,
which means "always the latest version". Measured: after a teacher edited the question in the bank, an **unpinned**
slot switched to the new question id; a **pinned** one (`version = 1`) kept the original, and the existing attempt
kept its own question id in both cases. The mirror therefore pins every slot at creation.

## 2. A finished attempt for a student who never took the quiz

The sequence is the one Moodle's own attempt walkthrough test uses, and each call is public:

1. `quiz_settings::create($quizid, $userid)`, `quiz_create_attempt(..., $timestart, false, $userid)`,
   `quiz_start_new_attempt(...)`, `quiz_attempt_save_started(...)`.
2. `quiz_attempt::process_submitted_actions($time, false, $post)` with `$post` keyed like a browser form
   (`<field prefix>answer`, `<prefix>sub0`, ..., plus `<prefix>:sequencecheck`). The legacy `slot => answers` form does
   not work for matching questions (it maps by the stem text), so the mirror builds the real form data.
3. `process_submit($time, false, $timefinish)` then `process_grade_submission($timefinish)`.
4. **Marks decided by the service.** For every slot whose mark differs from the service's mark (or that is manually
   graded) the mirror calls `question_attempt::manual_grade($comment, $mark, FORMAT_HTML)`, saves the usage
   (`question_engine::save_questions_usage_by_activity`), sets `quiz_attempts.sumgrades` from the usage total and calls
   `grade_calculator::recompute_final_grade($userid)`.

**Steps are kept**: 3 per ordinary question (started, answered, finished) and 4 for a written task (plus the manual
grade), which is what the responses report and the item analysis read. `time start` and `time finish` are the exam's
real times because the functions take them as arguments.

**Written tasks** (`essay`) finish in the state "needs grading"; the manual grade above is exactly what a teacher
does in the UI, so the attempt shows who graded it and when.

## 3. Moodle's own metrics over mirrored attempts

PHPUnit, five mirrored attempts, `quiz_statistics_report::get_all_stats_and_analysis()`:

```
quiz stats: n=5 sd=38.9764 KR-20 (internal consistency)=91.68
slot l-01 facility 0.80  discrimination 97.3      slot r-01 facility 0.80  discrimination 96.9
slot l-02 facility 0.40  discrimination 47.8      slot r-02 facility 0.60  discrimination 58.3
slot l-11 facility 0.80  discrimination 96.1      slot u-02 facility 0.40  discrimination 43.9
slot w-01 facility 0.76  discrimination 95.7      slot s-02 facility 0.80  discrimination 96.4
```

Facility and discrimination are sensible for the cohort. The responses data is complete: every slot has its response
summary ("first", "third", the matching summary, the essay text). **Not verified:** the rendered HTML of the responses
report page in a browser; the data it reads (question attempt steps) is what was checked.

## 4. Grade fidelity

Fixture: sections listening 25, reading 25, use of English 20, writing 15, speaking 15 (written equivalent), with
section maxima 7 (the matching item counts as 5), 3, 3, 5 and 5. Each question's maximum mark is
`weight x units / section max`, rounded to seven decimals (Moodle's precision), so the quiz total is 100.

| Case | Points per section | Exact service percent | Service shows | Moodle total | Moodle − exact |
| --- | --- | --- | --- | --- | --- |
| below the threshold | 6/1/3/5/5 | 79.761905 | 79.76 | 79.76190 | 4.8e-6 |
| **exactly at 80** | 7/3/0/5/5 | 80.000000 | 80.00 | 80.00000 | 0 |
| just above | 6/3/1/4/5 | 80.095238 | 80.10 | 80.09524 | 1.9e-6 |
| zero | 0/0/0/0/0 | 0 | 0.00 | 0.00000 | 0 |
| full marks | 7/3/3/5/5 | 100 | 100.00 | 100.00000 | 0 |

The largest difference seen is under 0.00001 of a percentage point, from the seven-decimal question marks and the
five-decimal quiz total. It never changes the two-decimal figure the service shows. **How it is resolved:** the mirror
compares the Moodle total, rounded half up to two decimals, with the statement's percent; a difference is logged as a
`divergence` and the attempt is still written (the service is right by definition). Nothing in Moodle compares a
percent with the threshold.

**The at-threshold row.** The service's `passed` is `false` for 80.00 under "strictly above". Moodle's total is
80.00000 there, and a Moodle "grade to pass" of 80 would call it a pass. That is why the mirror quiz is created with
**no grade to pass and no completion rule**, and why the tests include a control that shows what would have happened.

## 5. Item types

| Exam item | Moodle question | Marks | What is lost |
| --- | --- | --- | --- |
| `listen_choose`, `mcq`, `gap_choice` | `multichoice`, one answer, answers not shuffled | all or nothing | The audio itself (the transcript is put in the question text); for a gap, the passage is repeated in each question's text. |
| `match_audio` (counts as 5) | `match`: each clip's transcript is a stem, each place a choice | Moodle gives n/5 of the mark, the same as the service's five units | The audio; the service's own clip order. |
| `written_answers`, `template_text`, `form_fields` | `essay`, plain text | manual grade 0 to the item's maximum (the rubric's 0-5) scaled to the item's weight | The rubric's per-check detail (carried as the grader's comment when the export has it); word and character limits are not enforced because nobody types. |

Typed answers that the service accepts after its own normalisation are not in the published forms (they are in the
practice exercises). If one appears, `shortanswer` would autograde it differently from the service, and the "marks
decided by the service" step overrides it, so the mark stays right and only the response text is shown.

## 6. Risks

| Event | Result | Evidence |
| --- | --- | --- |
| Moodle upgrade | The calls are public but not `@api`-tagged, and Moodle changed them before (`process_finish` was deprecated in 5.0 for `process_submit` + `process_grade_submission`). All calls sit in two classes, run in CI on 5.2 and 5.3, and a failure is a clear 5xx, not a silent wrong grade. | Moodle's own `UPGRADING.md`. |
| Teacher edits a question in the bank | New version; the mirror quiz and its attempts are unchanged because slots are pinned. | Measured (above). |
| Course backup and restore | The mapping table is not in the backup (the same decision as the flow table, D16), so a restored course is a new course and gets new mirror quizzes when the service sends definitions again. The restored copy of a quiz is still hidden but loses the permission override (overrides are not restored by default), so every delivery re-applies the lock. **Not run** in the spike; reasoned from Moodle's backup rules and D16. | |
| The quiz is deleted | Attempts and questions go with it. The mapping row then points at nothing: the next delivery for it fails with a clear `mirror_quiz_missing` and the definition can be sent again. Measured: no attempts or categories left. | Measured |
| Same statement sent again | Looked up by the service's attempt id before anything is written; a unique index decides a race. | Product tests |
| Failure half way | One delegated transaction. **PHPUnit cannot show a rollback** (it wraps every test in one transaction), so this was run on the dev site's PostgreSQL: attempt and usage rows went from 0 to 1 inside the transaction and back to 0 after the exception. | Measured, dev site |
| A student reaches the quiz | A hidden activity is not listed, but `mod/quiz:attempt` was still **true** for the student in the hidden quiz's context (measured). The mirror therefore also prohibits it for the student archetype roles. | Measured |

## Gradebook decision

Measured on the same fixtures, with a quiz of grade 100 and with a quiz of grade 0:

| Quiz grade | Quiz grade item | Course total for a student with only a mirror attempt | Statistics |
| --- | --- | --- | --- |
| 100 | numeric, 0-100, created hidden | **79.76** (the mirror alone becomes the course total; with the "Rumbos result" item the total would be counted twice) | identical |
| **0** | **none** (`GRADE_TYPE_NONE`: no item is created) | no grade, course total unaffected | identical |

Options, and the choice:

- **A. Quiz grade 0 (chosen).** No grade item, so nothing can be double counted under any aggregation. The attempts
  still carry their marks (`quiz_attempts.sumgrades`), so the item analysis, the responses report and the attempt list
  work; the per-attempt marks show as "marks" and the Rumbos result item stays the one grade.
- B. Grade 100 and weight the item 0 in the category. Works only for "natural" aggregation (a weight of 0 means
  nothing in "mean of grades"), and a course's aggregation is the administrator's to change.
- C. Grade 100 in a category that is excluded from the total. Needs a category per course and still depends on the
  aggregation.
- D. Drop the Rumbos result item and use the quiz's grade. Not allowed: the passback and its completion rule depend on
  that item.

## Cost of the full build

About two to three working days (the spike was one day of it): the model and its validation, the quiz builder, the
attempt writer, the receiver, the table and upgrade step, the admin page, privacy, documentation and the tests.
