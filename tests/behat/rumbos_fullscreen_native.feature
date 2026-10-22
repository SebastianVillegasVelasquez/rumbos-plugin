@format @format_rumbos @javascript
Feature: The browser's own full screen and the activity popup
  In order to keep the screen mine while I open and close activities
  As a student
  I need an activity popup that does not end the browser's full screen

  Background:
    Given the following config values are set as admin:
      | config    | value                  | plugin        |
      | enabled   | 1                      | format_rumbos |
      | appurl    | https://rumbos.invalid | format_rumbos |
      | timeoutms | 120000                 | format_rumbos |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | rumbos |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity | name   | content          | course | idnumber | section |
      | page     | Page 1 | A page body text | C1     | page1    | 1       |
    And the Rumbos signing keys are generated afresh
    And I am on the "Course 1" course page logged in as student1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready

  Scenario: A real click on the app's button puts the map in the browser's full screen
    When the Rumbos app asks for full screen on after a real click
    Then the browser's full screen should be on the Rumbos map
    And the Rumbos app should have been sent "rumbos:host-fullscreen:true" last

  Scenario: Opening an activity keeps the browser's full screen, and so does closing it
    Given the Rumbos app asks for full screen on after a real click
    When the Rumbos app asks to open the activity "Page 1"
    Then the browser's full screen should be on the Rumbos map
    And the Rumbos popup should be inside the browser's full screen
    And I switch to "rumbos-popup" iframe
    And I should see "A page body text"
    And I switch to the main frame
    When I click on "Back to the map" "button" in the ".format-rumbos-popup" "css_element"
    Then ".format-rumbos-popup" "css_element" should not exist
    And the browser's full screen should be on the Rumbos map
    And the Rumbos exit control should own the top-right corner of the screen
