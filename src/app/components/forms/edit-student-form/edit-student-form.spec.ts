import { ComponentFixture, TestBed } from '@angular/core/testing';

import { EditStudentForm } from './edit-student-form';

describe('EditStudentForm', () => {
  let component: EditStudentForm;
  let fixture: ComponentFixture<EditStudentForm>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [EditStudentForm]
    })
    .compileComponents();

    fixture = TestBed.createComponent(EditStudentForm);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
