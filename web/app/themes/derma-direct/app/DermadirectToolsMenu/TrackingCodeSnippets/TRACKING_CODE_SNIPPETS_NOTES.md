# Tracking Code Snippets Implementation Notes

- A custom post type (CPT) is created with the following fields:
  - A **textarea** for entering the code snippet.
  - A **dropdown** to select whether the snippet should be rendered in the **head** or **footer**.
  - A **checkbox** to set the snippet as **active** or **inactive**.

- The implementation retrieves all posts of this CPT with the meta field **status** set to **active**.
- Based on the selected **render location**, the code snippet is dynamically injected into either the **head** or the **footer** of the site.


## NOTE:
Currently, the php snippets from live site are not imported. They will be imported once the other plugins and functionality is made and funcational.
