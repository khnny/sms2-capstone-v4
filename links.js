/*
 * Subsystem cards on the bestlinksms2portal.com landing page.
 *
 * To add a subsystem: copy one { ... } block, paste it at the end of the list
 * (put a comma between blocks), and change the values. Then push to GitHub.
 *
 *   name        Card title
 *   short       Small label above the title (e.g. "SMS2 · Research")
 *   description One or two sentences
 *   url         Full https link. Leave "" if it is not live yet.
 *   icon        research | student | finance | enrollment | library | clinic | guidance | hr | admin | grades | folder
 *   color       green | blue | purple | amber | teal | rose
 *   status      "live" (clickable) or "soon" (greyed out, "Coming soon")
 *   who         Who uses it (shown on the card)
 */
window.SUBSYSTEMS = [
  {
    name: "CRAD",
    short: "SMS2 · Research & Development",
    description: "Research titles, coordinators and advisers, chapters, progress updates, panel defenses, payments and clearance.",
    url: "https://crad.bestlinksms2portal.com",
    icon: "research",
    color: "green",
    status: "live",
    who: "Staff, faculty and students"
  },
  {
    name: "Student Portal",
    short: "SMS2 · Students",
    description: "Students sign in with their SMS2 account to follow their research, defenses, clearance, announcements and notifications.",
    url: "https://student.bestlinksms2portal.com/login.php?go=1",
    icon: "student",
    color: "blue",
    status: "live",
    who: "Students"
  }
  /* Example of a subsystem that is not live yet:
  ,{
    name: "Registrar",
    short: "SMS2 · Records",
    description: "Enrollment, grades and student records.",
    url: "",
    icon: "enrollment",
    color: "purple",
    status: "soon",
    who: "Registrar staff"
  }
  */
];
