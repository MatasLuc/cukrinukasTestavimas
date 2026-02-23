stages:
    - echo

echo:
    stage: echo
    script:
        - echo true
    allow_failure: true
